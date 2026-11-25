<?php

declare(strict_types=1);

namespace App\Review\UI\Http;

use App\Review\Application\Query\ClientReviews;
use App\Review\Application\Query\ClientReviewsView;
use App\Review\Application\Query\ReviewView;
use App\Review\Application\Review\ReviewAction;
use App\Review\Application\Review\SubmitReview;
use App\Review\Domain\Review\ReviewContent;
use App\Review\Domain\Review\ReviewRuleViolation;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentUser;
use App\Shared\Domain\Identifier\IdGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * C06 « Mes avis » (/espace-client/avis, Figma 230:13056) : prestations à évaluer, formulaire d'avis certifié
 * (/espace-client/avis/nouveau/{demande}), modification et suppression de ses avis, statut et réponse de l'artisan.
 */
#[Route('/espace-client/avis')]
final class ClientReviewsController extends AbstractController
{
    private const string CSRF = 'client_reviews';
    private const string UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function __construct(private readonly QueryBus $queries, private readonly CommandBus $commands, private readonly CurrentUser $user, private readonly IdGenerator $ids)
    {
    }

    #[Route('', name: 'client_reviews', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->page($request, null, null);
    }

    #[Route('/nouveau/{demande}', name: 'client_review_new', requirements: ['demande' => self::UUID], methods: ['GET', 'POST'])]
    public function create(Request $request, string $demande): Response
    {
        $view = $this->view();
        $item = $view->eligible($demande) ?? throw new NotFoundHttpException();
        if ($request->isMethod('POST')) {
            $error = $this->save($request, $this->ids->next(), $demande);
            if (null === $error) {
                $this->addFlash('success', 'Merci pour votre avis ! Il sera visible après vérification si la modération le demande.');

                return $this->redirectToRoute('client_reviews', ['onglet' => 'publies'], Response::HTTP_SEE_OTHER);
            }

            return $this->page($request, ['request_id' => $demande, 'artisan_name' => $item['artisan_name'], 'service' => $item['opens'], 'review' => null, 'error' => $error], $view, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->page($request, ['request_id' => $demande, 'artisan_name' => $item['artisan_name'], 'service' => $item['opens'], 'review' => null, 'error' => null], $view);
    }

    #[Route('/{id}/modifier', name: 'client_review_edit', requirements: ['id' => self::UUID], methods: ['GET', 'POST'])]
    public function edit(Request $request, string $id): Response
    {
        $view = $this->view();
        $review = $view->mine($id) ?? throw new NotFoundHttpException();
        $form = ['request_id' => $review->requestId, 'artisan_name' => $review->artisanName, 'service' => $review->serviceDate, 'review' => $review, 'error' => null];
        if ($request->isMethod('POST')) {
            $form['error'] = $this->save($request, $id, $review->requestId);
            if (null === $form['error']) {
                $this->addFlash('success', 'Avis modifié.');

                return $this->redirectToRoute('client_reviews', ['onglet' => 'publies'], Response::HTTP_SEE_OTHER);
            }

            return $this->page($request, $form, $view, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->page($request, $form, $view);
    }

    #[Route('/{id}/supprimer', name: 'client_review_delete', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function delete(Request $request, string $id): Response
    {
        $this->checked($request);
        try {
            $this->commands->dispatch(new ReviewAction($this->userId(), $id, ReviewAction::DELETE));
            $this->addFlash('success', 'Avis supprimé.');
        } catch (ReviewRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('client_reviews', ['onglet' => 'publies'], Response::HTTP_SEE_OTHER);
    }

    /**
     * @param array{request_id: string, artisan_name: string, service: \DateTimeImmutable, review: ?ReviewView, error: ?string}|null $form
     */
    private function page(Request $request, ?array $form, ?ClientReviewsView $view, int $status = Response::HTTP_OK): Response
    {
        $view ??= $this->view();

        return $this->render('client/reviews/index.html.twig', [
            'view' => $view,
            'tab' => 'publies' === $request->query->getString('onglet') ? 'publies' : 'a-donner',
            'form' => $form,
            'input' => $request->isMethod('POST') ? $request->request->all() : null,
            'criteria' => ReviewContent::CRITERIA,
            'text_min' => ReviewContent::TEXT_MIN,
            'text_max' => ReviewContent::TEXT_MAX,
            'photos_max' => ReviewContent::PHOTOS_MAX,
            'csrf_id' => self::CSRF,
        ], new Response(null, $status));
    }

    /** Dépôt ou modification ; renvoie le message d'erreur, ou null en cas de succès. */
    private function save(Request $request, string $reviewId, string $requestId): ?string
    {
        $this->checked($request);
        $p = $request->request;
        $sub = [];
        foreach (array_keys(ReviewContent::CRITERIA) as $criterion) {
            $value = $p->getInt('sous_'.$criterion);
            if ($value > 0) {
                $sub[$criterion] = $value;
            }
        }
        $uploads = [];
        foreach ($request->files->all('photos') as $file) {
            if ($file instanceof UploadedFile) {
                if (!$file->isValid()) {
                    return 'Une photo n’a pas pu être reçue : vérifiez son poids et réessayez.';
                }
                $uploads[] = ['path' => $file->getPathname(), 'name' => $file->getClientOriginalName()];
            }
        }
        $recommends = $p->getString('recommande');
        try {
            $this->commands->dispatch(new SubmitReview(
                $this->userId(),
                $reviewId,
                $requestId,
                $p->getInt('note'),
                $sub,
                $p->getString('titre'),
                $p->getString('avis'),
                $p->getString('date_prestation') ?: null,
                '' === $recommends ? null : 'oui' === $recommends,
                array_values(array_filter($p->all('garder'), 'is_string')),
                $uploads,
            ));
        } catch (ReviewRuleViolation $e) {
            return $e->getMessage();
        }

        return null;
    }

    private function view(): ClientReviewsView
    {
        $view = $this->queries->ask(new ClientReviews($this->userId()));
        \assert($view instanceof ClientReviewsView);

        return $view;
    }

    private function checked(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException();
        }
    }

    private function userId(): string
    {
        return $this->user->id() ?? throw new AccessDeniedHttpException();
    }
}
