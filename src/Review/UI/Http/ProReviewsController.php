<?php

declare(strict_types=1);

namespace App\Review\UI\Http;

use App\Review\Application\Query\ArtisanReviews;
use App\Review\Application\Query\ArtisanReviewsView;
use App\Review\Application\Review\RemindReviewer;
use App\Review\Application\Review\ReviewAction;
use App\Review\Domain\Review\Review;
use App\Review\Domain\Review\ReviewContent;
use App\Review\Domain\Review\ReviewRuleViolation;
use App\Shared\Application\Bus\Command;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * PR09 « Avis reçus » (/espace-pro/avis, Figma 233:13842) : synthèse (note, répartition, évolution, sous-notes), avis
 * et réponses (une réponse publique modérée, signalement), solliciter des avis (relance unique du client).
 */
#[Route('/espace-pro/avis')]
final class ProReviewsController extends AbstractController
{
    private const string CSRF = 'pro_reviews';
    private const string UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function __construct(private readonly QueryBus $queries, private readonly CommandBus $commands, private readonly CurrentUser $user)
    {
    }

    #[Route('', name: 'pro_reviews', methods: ['GET'])]
    public function index(): Response
    {
        $view = $this->queries->ask(new ArtisanReviews($this->userId()));
        if (!$view instanceof ArtisanReviewsView) {
            throw new NotFoundHttpException();
        }

        return $this->render('pro/reviews/index.html.twig', [
            'view' => $view,
            'criteria' => ReviewContent::CRITERIA,
            'reply_max' => Review::REPLY_MAX,
            'csrf_id' => self::CSRF,
        ]);
    }

    #[Route('/{id}/reponse', name: 'pro_review_answer', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function answer(Request $request, string $id): Response
    {
        return $this->run($request, new ReviewAction($this->userId(), $id, ReviewAction::ANSWER, $request->request->getString('reponse')), 'Réponse enregistrée : elle sera publiée après vérification si la modération le demande.');
    }

    #[Route('/{id}/signaler', name: 'pro_review_report', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function report(Request $request, string $id): Response
    {
        return $this->run($request, new ReviewAction($this->userId(), $id, ReviewAction::REPORT, $request->request->getString('motif')), 'Avis signalé : il reste en ligne pendant l’examen par notre équipe.');
    }

    #[Route('/relancer/{demande}', name: 'pro_review_remind', requirements: ['demande' => self::UUID], methods: ['POST'])]
    public function remind(Request $request, string $demande): Response
    {
        return $this->run($request, new RemindReviewer($this->userId(), $demande), 'Client relancé : il reçoit une invitation à donner son avis.');
    }

    private function run(Request $request, Command $command, string $success): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException();
        }
        try {
            $this->commands->dispatch($command);
            $this->addFlash('success', $success);
        } catch (ReviewRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('pro_reviews', [], Response::HTTP_SEE_OTHER);
    }

    private function userId(): string
    {
        return $this->user->id() ?? throw new AccessDeniedHttpException();
    }
}
