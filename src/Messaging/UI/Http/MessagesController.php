<?php

declare(strict_types=1);

namespace App\Messaging\UI\Http;

use App\Messaging\Application\Conversation\ConversationAction;
use App\Messaging\Application\Conversation\ReportMessage;
use App\Messaging\Application\Conversation\SendMessage;
use App\Messaging\Application\Conversation\StartConversation;
use App\Messaging\Application\Port\MessagingDirectory;
use App\Messaging\Application\Query\ConversationReader;
use App\Messaging\Application\Query\Conversations;
use App\Messaging\Application\Query\ConversationsPage;
use App\Messaging\Application\Query\ConversationThread;
use App\Messaging\Application\Query\OpenConversation;
use App\Messaging\Domain\Conversation\MessagingRuleViolation;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentUser;
use App\Shared\Domain\Identifier\IdGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * C04 / PR07 — messagerie commune aux deux espaces (mêmes composants) ; l'espace (client ou pro) fixe le gabarit et
 * les routes. Envoi limité par utilisateur et par fil (`message_user`, `message_thread`), jetons CSRF, lecture marquée à
 * l'ouverture et à chaque rafraîchissement du fil.
 */
abstract class MessagesController extends AbstractController
{
    protected const string CSRF = 'messaging';

    public function __construct(
        protected readonly QueryBus $queries,
        protected readonly CommandBus $commands,
        protected readonly CurrentUser $user,
        protected readonly IdGenerator $ids,
        protected readonly ConversationReader $reader,
        protected readonly MessagingDirectory $directory,
        #[Target('message_user.limiter')]
        private readonly RateLimiterFactoryInterface $userLimiter,
        #[Target('message_thread.limiter')]
        private readonly RateLimiterFactoryInterface $threadLimiter,
    ) {
    }

    /** Préfixe des routes de l'espace : `client` ou `pro`. */
    abstract protected function space(): string;

    abstract protected function template(): string;

    protected function page(Request $request, ?string $conversationId): Response
    {
        $userId = $this->userId();
        $filter = $request->query->getString('filtre', 'all');
        $list = $this->queries->ask(new Conversations($userId, $filter, $request->query->getString('q'), max(1, $request->query->getInt('page', 1))));
        $thread = null;
        if (null !== $conversationId) {
            $thread = $this->queries->ask(new OpenConversation($userId, $conversationId));
            if (!$thread instanceof ConversationThread) {
                throw new NotFoundHttpException();
            }
            $this->commands->dispatch(new ConversationAction($userId, $conversationId, ConversationAction::READ));
        }

        return $this->render($this->template(), [
            'list' => $list instanceof ConversationsPage ? $list : null,
            'thread' => $thread,
            'filter' => $filter,
            'q' => $request->query->getString('q'),
            'space' => $this->space(),
        ] + $this->extra($userId));
    }

    /**
     * Données propres à l'espace (réponses types de l'artisan…).
     *
     * @return array<string, mixed>
     */
    protected function extra(string $userId): array
    {
        return [];
    }

    protected function fragment(string $conversationId): Response
    {
        $userId = $this->userId();
        $thread = $this->queries->ask(new OpenConversation($userId, $conversationId));
        if (!$thread instanceof ConversationThread) {
            throw new NotFoundHttpException();
        }
        $this->commands->dispatch(new ConversationAction($userId, $conversationId, ConversationAction::READ));
        $response = $this->render('messaging/_messages.html.twig', ['thread' => $thread, 'space' => $this->space()]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    protected function send(Request $request, string $conversationId): Response
    {
        $userId = $this->userId();
        try {
            $this->guard($request, $userId, $conversationId);
            $this->commands->dispatch(new SendMessage($userId, $conversationId, $this->ids->next(), $request->request->getString('message')));
        } catch (MessagingRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirect($this->generateUrl($this->space().'_conversation', ['id' => $conversationId]).'#dernier', Response::HTTP_SEE_OTHER);
    }

    protected function compose(Request $request): Response
    {
        $userId = $this->userId();
        $requestId = '' === $request->query->getString('demande') ? null : $request->query->getString('demande');
        $artisanId = '' === $request->query->getString('artisan') ? null : $request->query->getString('artisan');
        $existing = $this->reader->idFor($userId, $requestId, $artisanId);
        if (null !== $existing && $request->isMethod('GET')) {
            return $this->redirectToRoute($this->space().'_conversation', ['id' => $existing]);
        }
        $error = null;
        if ($request->isMethod('POST')) {
            try {
                $this->guard($request, $userId, 'nouveau');
                $this->commands->dispatch(new StartConversation($userId, $this->ids->next(), $this->ids->next(), $requestId, $artisanId, $request->request->getString('message')));
                $id = $this->reader->idFor($userId, $requestId, $artisanId) ?? throw new NotFoundHttpException();

                return $this->redirect($this->generateUrl($this->space().'_conversation', ['id' => $id]).'#dernier', Response::HTTP_SEE_OTHER);
            } catch (MessagingRuleViolation $e) {
                $error = $e->getMessage();
            }
        }
        $context = [
            'request' => null === $requestId ? null : $this->directory->request($requestId),
            'artisan' => null === $artisanId ? null : $this->directory->artisan($artisanId),
        ];

        return $this->render('messaging/compose.html.twig', [
            'context' => $context,
            'query' => array_filter(['demande' => $requestId, 'artisan' => $artisanId]),
            'error' => $error,
            'message' => $request->request->getString('message'),
            'space' => $this->space(),
            'layout' => $this->template(),
        ], new Response(null, null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    protected function act(Request $request, string $conversationId): Response
    {
        $userId = $this->userId();
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException();
        }
        $action = $request->request->getString('action');
        try {
            if ('report' === $action) {
                $this->commands->dispatch(new ReportMessage($userId, $conversationId, $request->request->getString('message'), $request->request->getString('motif')));
                $this->addFlash('success', 'Message signalé : notre équipe de modération va l’examiner.');
            } else {
                $this->commands->dispatch(new ConversationAction($userId, $conversationId, $action));
                $this->addFlash('success', [ConversationAction::ARCHIVE => 'Conversation archivée.', ConversationAction::UNARCHIVE => 'Conversation sortie des archives.', ConversationAction::BLOCK => 'Interlocuteur bloqué : il ne peut plus vous écrire dans cette conversation.', ConversationAction::UNBLOCK => 'Blocage levé.'][$action] ?? 'C’est fait.');
            }
        } catch (MessagingRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute(ConversationAction::ARCHIVE === $action ? $this->space().'_messages' : $this->space().'_conversation', ConversationAction::ARCHIVE === $action ? [] : ['id' => $conversationId], Response::HTTP_SEE_OTHER);
    }

    protected function userId(): string
    {
        return $this->user->id() ?? throw new AccessDeniedHttpException();
    }

    private function guard(Request $request, string $userId, string $thread): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException();
        }
        if (!$this->userLimiter->create($userId)->consume()->isAccepted() || !$this->threadLimiter->create($userId.'|'.$thread)->consume()->isAccepted()) {
            throw new MessagingRuleViolation('Vous envoyez beaucoup de messages : patientez un instant avant de réessayer.');
        }
    }
}
