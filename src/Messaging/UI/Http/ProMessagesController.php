<?php

declare(strict_types=1);

namespace App\Messaging\UI\Http;

use App\Messaging\Application\Conversation\DeleteQuickReply;
use App\Messaging\Application\Conversation\SaveConversationNote;
use App\Messaging\Application\Conversation\SaveQuickReply;
use App\Messaging\Application\Port\QuickReplies;
use App\Messaging\Domain\Conversation\MessagingRuleViolation;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * PR07 (/espace-pro/messages, Figma 232:13464) — messagerie de l'artisan : composants de C04, onglets Clients /
 * Prospects, encart client (note privée), réponses types (PR07.1).
 */
#[Route('/espace-pro/messages')]
final class ProMessagesController extends MessagesController
{
    private const string UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    private QuickReplies $quickReplies;

    #[Required]
    public function setQuickReplies(QuickReplies $quickReplies): void
    {
        $this->quickReplies = $quickReplies;
    }

    #[Route('', name: 'pro_messages', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->page($request, null);
    }

    #[Route('/nouveau', name: 'pro_message_new', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        return $this->compose($request);
    }

    #[Route('/reponses-types', name: 'pro_quick_reply_add', methods: ['POST'])]
    public function addQuickReply(Request $request): Response
    {
        $userId = $this->checked($request);
        try {
            $this->commands->dispatch(new SaveQuickReply($userId, $this->ids->next(), $request->request->getString('title'), $request->request->getString('body')));
            $this->addFlash('success', 'Réponse type enregistrée.');
        } catch (MessagingRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->back($request);
    }

    #[Route('/reponses-types/{reply}/supprimer', name: 'pro_quick_reply_delete', requirements: ['reply' => self::UUID], methods: ['POST'])]
    public function deleteQuickReply(Request $request, string $reply): Response
    {
        $userId = $this->checked($request);
        try {
            $this->commands->dispatch(new DeleteQuickReply($userId, $reply));
            $this->addFlash('success', 'Réponse type supprimée.');
        } catch (MessagingRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->back($request);
    }

    #[Route('/{id}', name: 'pro_conversation', requirements: ['id' => self::UUID], methods: ['GET'])]
    public function show(Request $request, string $id): Response
    {
        return $this->page($request, $id);
    }

    #[Route('/{id}', name: 'pro_conversation_send', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function post(Request $request, string $id): Response
    {
        return $this->send($request, $id);
    }

    #[Route('/{id}/fil', name: 'pro_conversation_messages', requirements: ['id' => self::UUID], methods: ['GET'])]
    public function messages(string $id): Response
    {
        return $this->fragment($id);
    }

    #[Route('/{id}/pieces-jointes/{file}', name: 'pro_conversation_attachment', requirements: ['id' => self::UUID, 'file' => self::UUID], methods: ['GET'])]
    public function download(Request $request, string $id, string $file): Response
    {
        return $this->attachment($request, $id, $file);
    }

    #[Route('/{id}/action', name: 'pro_conversation_action', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function action(Request $request, string $id): Response
    {
        return $this->act($request, $id);
    }

    #[Route('/{id}/note', name: 'pro_conversation_note', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function note(Request $request, string $id): Response
    {
        $userId = $this->checked($request);
        try {
            $this->commands->dispatch(new SaveConversationNote($userId, $id, $request->request->getString('note')));
            $this->addFlash('success', 'Note enregistrée.');
        } catch (MessagingRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('pro_conversation', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    protected function space(): string
    {
        return 'pro';
    }

    protected function template(): string
    {
        return 'pro/messages/index.html.twig';
    }

    protected function extra(string $userId): array
    {
        return ['quick_replies' => $this->quickReplies->of($userId), 'quick_replies_max' => QuickReplies::MAX];
    }

    private function checked(Request $request): string
    {
        $userId = $this->userId();
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException();
        }

        return $userId;
    }

    /** Retour à la conversation d'où vient le formulaire (champ `retour`), sinon à la liste. */
    private function back(Request $request): Response
    {
        $id = $request->request->getString('retour');

        return 1 === preg_match('/^'.self::UUID.'$/', $id)
            ? $this->redirectToRoute('pro_conversation', ['id' => $id], Response::HTTP_SEE_OTHER)
            : $this->redirectToRoute('pro_messages', [], Response::HTTP_SEE_OTHER);
    }
}
