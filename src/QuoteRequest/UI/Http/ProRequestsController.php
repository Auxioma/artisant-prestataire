<?php

declare(strict_types=1);

namespace App\QuoteRequest\UI\Http;

use App\QuoteRequest\Application\Port\ProRequestReader;
use App\QuoteRequest\Application\Pro\AvailableRequests;
use App\QuoteRequest\Application\Pro\IgnoreReason;
use App\QuoteRequest\Application\Pro\IgnoreRequest;
use App\QuoteRequest\Application\Pro\MarkRequestViewed;
use App\QuoteRequest\Application\Pro\ProRequestAccess;
use App\QuoteRequest\Application\Pro\ProRequestDetail;
use App\QuoteRequest\Application\Pro\ProRequestFilter;
use App\QuoteRequest\Application\Pro\ProRequestPage;
use App\QuoteRequest\Application\Pro\ProRequestView;
use App\QuoteRequest\Application\Pro\RequestState;
use App\QuoteRequest\Domain\Request\BudgetRange;
use App\QuoteRequest\Domain\Request\RequestRuleViolation;
use App\QuoteRequest\Domain\Request\Timing;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentUser;
use App\Shared\Application\Storage\FileReader;
use App\Shared\Application\Storage\StoredFileNotFound;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * PR02 (/espace-pro/demandes, Figma 159:3176) — demandes reçues : recherche, filtres PR02.1, tri, cartes PR02.2,
 * « Ignorer » (motif facultatif) et rétablissement, aperçu Q15 d'un compte en attente de vérification.
 */
#[Route('/espace-pro/demandes')]
final class ProRequestsController extends AbstractController
{
    public const array STATES = ['non-lue' => RequestState::Unread, 'consultee' => RequestState::Viewed, 'ignoree' => RequestState::Ignored];
    public const array PERIODS = ['1' => 'Dernières 24 h', '7' => '7 derniers jours', '30' => '30 derniers jours'];
    public const array DISTANCES = ['10' => 'Moins de 10 km', '20' => 'Moins de 20 km', '50' => 'Moins de 50 km'];
    public const array SORTS = [ProRequestFilter::SORT_RECENT => 'Plus récentes', ProRequestFilter::SORT_CLOSEST => 'Plus proches', ProRequestFilter::SORT_URGENT => 'Urgentes d’abord'];
    private const string UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
    private const string CSRF = 'pro_requests';
    private const int PER_PAGE = 10;

    public function __construct(private readonly QueryBus $queries, private readonly CommandBus $commands, private readonly CurrentUser $user)
    {
    }

    #[Route('', name: 'pro_requests', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $page = $this->queries->ask(new AvailableRequests($this->userId(), $this->filter($filters), max(1, $request->query->getInt('page', 1)), self::PER_PAGE));

        return $this->render('pro/requests/index.html.twig', [
            'page' => $page instanceof ProRequestPage ? $page : null,
            'filters' => $filters,
            'active' => array_filter($filters, static fn (string $v): bool => '' !== $v),
            'states' => self::STATES,
            'periods' => self::PERIODS,
            'distances' => self::DISTANCES,
            'sorts' => self::SORTS,
            'budgets' => array_filter(BudgetRange::cases(), static fn (BudgetRange $b): bool => BudgetRange::Unknown !== $b),
            'timings' => Timing::cases(),
            'reasons' => IgnoreReason::cases(),
            'csrf_id' => self::CSRF,
        ]);
    }

    /** PR03 (Figma 13:2) — détail d'une demande ; l'ouvrir la passe en « consultée » (gratuit, RG-110). */
    #[Route('/{id}', name: 'pro_request', requirements: ['id' => self::UUID], methods: ['GET'])]
    public function show(string $id): Response
    {
        $view = $this->queries->ask(new ProRequestDetail($this->userId(), $id));
        if (!$view instanceof ProRequestView) {
            throw new NotFoundHttpException();
        }
        $this->commands->dispatch(new MarkRequestViewed($this->userId(), $id));

        return $this->render('pro/requests/show.html.twig', [
            'view' => $view,
            'r' => $view->card,
            'verified' => RequestState::Preview !== $view->card->state,
            'reasons' => IgnoreReason::cases(),
            'csrf_id' => self::CSRF,
        ]);
    }

    /** PR02.2 « Ignorer » (motif facultatif) ou « Rétablir ». */
    #[Route('/{id}/ignorer', name: 'pro_request_ignore', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function ignore(Request $request, string $id): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Jeton CSRF invalide.');
        }
        $restore = $request->request->getBoolean('restore');
        try {
            $this->commands->dispatch(new IgnoreRequest($this->userId(), $id, $request->request->getString('reason') ?: null, $restore));
            $this->addFlash('success', $restore ? 'Demande remise dans votre liste.' : 'Demande ignorée : retrouvez-la avec le filtre « Ignorée ».');
        } catch (RequestRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }
        $back = $request->request->getString('back');

        return $this->redirect(str_starts_with($back, '/espace-pro/demandes') ? $back : $this->generateUrl('pro_requests'), Response::HTTP_SEE_OTHER);
    }

    /** Photo d'une demande (vignette PR02, visionneuse PR03) : artisan y ayant accès, fichier analysé, lecture journalisée. */
    #[Route('/{id}/photos/{file}', name: 'pro_request_photo', requirements: ['id' => self::UUID, 'file' => self::UUID], methods: ['GET'])]
    public function photo(Request $request, string $id, string $file, ProRequestReader $reader, FileReader $files): Response
    {
        if (!$this->isGranted(ProRequestAccess::VIEW, $id) || !\in_array($file, $reader->photos($id), true)) {
            throw new NotFoundHttpException();
        }
        try {
            $stored = $files->available($file);
            $stream = $files->openForActor($file, 'user', $this->userId(), $request->getClientIp());
        } catch (StoredFileNotFound) {
            throw new NotFoundHttpException();
        }

        return new StreamedResponse(static function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, Response::HTTP_OK, [
            'Content-Type' => $stored->type->value,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, max-age=600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array{q: string, metier: string, statut: string, date: string, distance: string, budget: string, delai: string, directes: string, tri: string} */
    private function filters(Request $request): array
    {
        $q = $request->query;
        $pick = static fn (string $key, array $allowed): string => \in_array($q->getString($key), $allowed, true) ? $q->getString($key) : '';

        return [
            'q' => trim(mb_substr($q->getString('q'), 0, 80)),
            'metier' => 1 === preg_match('/^'.self::UUID.'$/', $q->getString('metier')) ? $q->getString('metier') : '',
            'statut' => $pick('statut', array_keys(self::STATES)),
            'date' => $pick('date', array_map('strval', array_keys(self::PERIODS))),
            'distance' => $pick('distance', array_map('strval', array_keys(self::DISTANCES))),
            'budget' => null === BudgetRange::tryFrom($q->getString('budget')) ? '' : $q->getString('budget'),
            'delai' => null === Timing::tryFrom($q->getString('delai')) ? '' : $q->getString('delai'),
            'directes' => '1' === $q->getString('directes') ? '1' : '',
            'tri' => $pick('tri', array_keys(self::SORTS)),
        ];
    }

    /** @param array{q: string, metier: string, statut: string, date: string, distance: string, budget: string, delai: string, directes: string, tri: string} $f */
    private function filter(array $f): ProRequestFilter
    {
        return new ProRequestFilter(
            '' === $f['metier'] ? null : $f['metier'],
            '' === $f['distance'] ? null : (int) $f['distance'],
            '' === $f['delai'] ? null : $f['delai'],
            '' === $f['date'] ? null : (int) $f['date'],
            '' === $f['budget'] ? null : $f['budget'],
            '1' === $f['directes'],
            self::STATES[$f['statut']] ?? null,
            '' === $f['tri'] ? ProRequestFilter::SORT_RECENT : $f['tri'],
            $f['q'],
        );
    }

    private function userId(): string
    {
        return $this->user->id() ?? throw new AccessDeniedHttpException();
    }
}
