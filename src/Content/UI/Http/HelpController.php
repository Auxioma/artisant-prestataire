<?php

declare(strict_types=1);

namespace App\Content\UI\Http;

use App\Content\Application\Faq\FaqQuery;
use App\Content\Application\Faq\FaqQuestion;
use App\Content\Application\Faq\FaqTopic;
use App\Content\Application\Faq\VoteFaqAnswer;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\IpAnonymizer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * P14 (Figma 224:11220) — aide : recherche plein texte avec suggestions, rubriques, questions en accordéon (une question
 * = une URL indexable, balisage FAQPage), vote « Cette réponse vous a-t-elle aidé ? » (un négatif propose le
 * formulaire de contact pré-rempli), encart contact. Contenu importé (`app:faq:import`) avant l'éditeur BO10.4.
 */
final class HelpController extends AbstractController
{
    private const string SLUG = '[a-z0-9]+(?:-[a-z0-9]+)*';
    private const int POPULAR = 6;
    private const int RESULTS = 20;
    private const int SUGGESTIONS = 6;
    private const string FLASH = 'faq_voted';

    public function __construct(private readonly QueryBus $queries)
    {
    }

    #[Route('/aide', name: 'content_faq', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $term = trim($request->query->getString('q'));
        $response = $this->render('content/help/index.html.twig', [
            'topics' => $this->topics(),
            'popular' => $this->questions(FaqQuery::popular(self::POPULAR)),
            'term' => $term,
            'results' => '' === $term ? null : $this->questions(FaqQuery::search($term, self::RESULTS)),
        ]);
        if ('' !== $term) {
            $response->headers->set('X-Robots-Tag', 'noindex, follow');
        }

        return $response;
    }

    #[Route('/aide/{topic}', name: 'content_faq_topic', requirements: ['topic' => self::SLUG], methods: ['GET'])]
    public function topic(string $topic): Response
    {
        $current = array_values(array_filter($this->topics(), static fn (FaqTopic $t): bool => $t->slug === $topic))[0] ?? throw new NotFoundHttpException('Rubrique introuvable.');

        return $this->render('content/help/topic.html.twig', [
            'topic' => $current,
            'topics' => $this->topics(),
            'questions' => $this->questions(FaqQuery::topic($topic)),
        ]);
    }

    #[Route('/aide/{topic}/{question}', name: 'content_faq_question', requirements: ['topic' => self::SLUG, 'question' => self::SLUG], methods: ['GET'])]
    public function question(Request $request, string $topic, string $question): Response
    {
        $entry = $this->queries->ask(FaqQuery::question($topic, $question));
        if (!$entry instanceof FaqQuestion) {
            throw new NotFoundHttpException('Question introuvable.');
        }
        $session = $request->hasSession() ? $request->getSession() : null;
        $flash = $session instanceof FlashBagAwareSessionInterface ? $session->getFlashBag()->get(self::FLASH) : [];

        return $this->render('content/help/question.html.twig', [
            'q' => $entry,
            'related' => array_values(array_filter($this->questions(FaqQuery::topic($topic)), static fn (FaqQuestion $r): bool => $r->id !== $entry->id)),
            'voted' => \is_string($flash[0] ?? null) ? $flash[0] : null,
        ]);
    }

    #[Route('/aide/{topic}/{question}/vote', name: 'content_faq_vote', requirements: ['topic' => self::SLUG, 'question' => self::SLUG], methods: ['POST'])]
    public function vote(Request $request, string $topic, string $question, CommandBus $commands, IpAnonymizer $ipAnonymizer): Response
    {
        if (!$this->isCsrfTokenValid('submit', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Jeton CSRF invalide.');
        }
        $entry = $this->queries->ask(FaqQuery::question($topic, $question));
        if (!$entry instanceof FaqQuestion) {
            throw new NotFoundHttpException();
        }
        $helpful = '1' === $request->request->getString('helpful');
        $visitor = $ipAnonymizer->hash($request->getClientIp()) ?? hash('sha256', (string) $request->getClientIp());
        $commands->dispatch(new VoteFaqAnswer($entry->id, $visitor, $helpful));
        $contact = $this->contactUrl($entry);

        if (str_contains((string) $request->headers->get('Accept'), 'application/json')) {
            return new JsonResponse(['helpful' => $helpful, 'contact' => $helpful ? null : $contact]);
        }
        $this->addFlash(self::FLASH, $helpful ? 'yes' : 'no');

        return $this->redirectToRoute('content_faq_question', ['topic' => $topic, 'question' => $question, '_fragment' => 'avis'], Response::HTTP_SEE_OTHER);
    }

    /** P14.1 — suggestions instantanées (questions correspondant à la saisie). */
    #[Route('/api/aide/suggestions', name: 'content_faq_suggest', methods: ['GET'])]
    public function suggest(Request $request, #[Autowire(service: 'limiter.autocomplete')] RateLimiterFactoryInterface $limiter): JsonResponse
    {
        if (!$limiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return new JsonResponse(['suggestions' => []], Response::HTTP_TOO_MANY_REQUESTS);
        }
        $results = $this->questions(FaqQuery::search($request->query->getString('q'), self::SUGGESTIONS));

        return new JsonResponse(['suggestions' => array_map(fn (FaqQuestion $q): array => [
            'label' => $q->question,
            'topic' => $q->topicTitle,
            'url' => $this->generateUrl('content_faq_question', ['topic' => $q->topicSlug, 'question' => $q->slug]),
        ], $results)], Response::HTTP_OK, ['Cache-Control' => 'public, max-age=300']);
    }

    private function contactUrl(FaqQuestion $entry): string
    {
        return $this->generateUrl('content_contact', ['message' => 'À propos de « '.$entry->question.' » : ']);
    }

    /** @return list<FaqTopic> */
    private function topics(): array
    {
        $topics = $this->queries->ask(FaqQuery::topics());

        return array_values(array_filter(\is_array($topics) ? $topics : [], static fn (mixed $t): bool => $t instanceof FaqTopic));
    }

    /** @return list<FaqQuestion> */
    private function questions(FaqQuery $query): array
    {
        $questions = $this->queries->ask($query);

        return array_values(array_filter(\is_array($questions) ? $questions : [], static fn (mixed $q): bool => $q instanceof FaqQuestion));
    }
}
