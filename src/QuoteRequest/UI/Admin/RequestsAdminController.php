<?php

declare(strict_types=1);

namespace App\QuoteRequest\UI\Admin;

use App\Catalog\Application\Geo\Query\Departments;
use App\Catalog\Application\Query\JobIndexEntries;
use App\Catalog\Application\Query\JobIndexEntry;
use App\QuoteRequest\Application\Admin\AdminRequestDetail;
use App\QuoteRequest\Application\Admin\AdminRequestList;
use App\QuoteRequest\Application\Admin\AdminRequestPage;
use App\QuoteRequest\Application\Admin\AdminRequestRows;
use App\QuoteRequest\Application\Admin\AdminRequestView;
use App\QuoteRequest\Application\Moderation\ModerateQuoteRequests;
use App\QuoteRequest\Application\Moderation\ModerationRejected;
use App\QuoteRequest\Domain\Request\RejectionReason;
use App\QuoteRequest\Domain\Request\RequestSource;
use App\QuoteRequest\Domain\Request\RequestStatus;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentAdministrator;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * BO04 (/admin/demandes, Figma 186:1669) — supervision des demandes : indicateurs, filtres, liste, fiche, modération
 * (valider, refuser avec motif, clôturer — une ou plusieurs demandes), export CSV. L'onglet Devis (BO04.2) arrive avec
 * le lot Devis.
 */
#[Route('/admin')]
final class RequestsAdminController extends AbstractController
{
    private const int PER_PAGE = 25;
    private const string CSRF = 'admin_requests';
    private const string CSV_SEPARATOR = ';';

    public function __construct(
        private readonly QueryBus $queries,
        private readonly CommandBus $commands,
        private readonly CurrentAdministrator $admin,
        private readonly SettingsProvider $settings,
    ) {
    }

    #[Route('/demandes', name: 'admin_requests', methods: ['GET'])]
    #[IsGranted('admin.requests.view')]
    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $page = $this->queries->ask($this->query($filters, max(1, $request->query->getInt('page', 1))));
        \assert($page instanceof AdminRequestPage);
        $selected = $request->query->getString('demande');
        $record = '' === $selected ? null : $this->queries->ask(new AdminRequestDetail($selected));

        return $this->render('admin/requests/index.html.twig', [
            'page' => $page,
            'filters' => $filters,
            'record' => $record instanceof AdminRequestView ? $record : null,
            'statuses' => RequestStatus::cases(),
            'sources' => RequestSource::cases(),
            'reasons' => RejectionReason::cases(),
            'jobs' => $this->jobs(),
            'departments' => $this->departments(),
            'threshold' => $this->settings->int(SettingKey::ModerationRequestRiskThreshold),
            'csrf_id' => self::CSRF,
        ]);
    }

    /** BO04.1 — valider, refuser (motif normalisé) ou clôturer la sélection. */
    #[Route('/demandes/decision', name: 'admin_requests_decision', methods: ['POST'])]
    #[IsGranted('admin.requests.edit')]
    public function decide(Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Jeton CSRF invalide.');
        }
        $ids = array_values(array_filter($request->request->all('ids'), \is_string(...)));
        $decision = $request->request->getString('decision');
        try {
            $this->commands->dispatch(new ModerateQuoteRequests($this->admin->id() ?? throw new AccessDeniedHttpException(), $ids, $decision, $request->request->getString('reason') ?: null, $request->request->getString('comment') ?: null));
            $count = \count($ids);
            $this->addFlash('success', match ($decision) {
                ModerateQuoteRequests::APPROVE => 1 === $count ? 'Demande validée : elle est publiée.' : \sprintf('%d demandes validées : elles sont publiées.', $count),
                ModerateQuoteRequests::REJECT => 1 === $count ? 'Demande refusée : le client est prévenu (E-10b).' : \sprintf('%d demandes refusées : les clients sont prévenus (E-10b).', $count),
                default => 1 === $count ? 'Demande clôturée.' : \sprintf('%d demandes clôturées.', $count),
            });
        } catch (ModerationRejected $e) {
            $this->addFlash('error', $e->getMessage());
        }
        $back = $request->request->getString('back');

        return $this->redirect(str_starts_with($back, '/admin/demandes') ? $back : $this->generateUrl('admin_requests'), Response::HTTP_SEE_OTHER);
    }

    /** Export CSV des demandes filtrées (Figma « Exporter CSV ») ; aucune donnée personnelle du client. */
    #[Route('/demandes/export', name: 'admin_requests_export', methods: ['GET'])]
    #[IsGranted('admin.requests.export')]
    public function export(Request $request, AdminRequestRows $rows): StreamedResponse
    {
        $query = $this->query($this->filters($request), 1);
        $statuses = array_column(array_map(static fn (RequestStatus $s): array => [$s->value, $s->adminLabel()], RequestStatus::cases()), 1, 0);
        $response = new StreamedResponse(static function () use ($rows, $query, $statuses): void {
            $out = fopen('php://output', 'w');
            if (false === $out) {
                return;
            }
            fwrite($out, "\u{FEFF}");
            fputcsv($out, ['N°', 'Métier', 'Ville', 'Statut', 'Artisans notifiés', 'Devis', 'Score anti-spam', 'Source', 'Envoyée le'], self::CSV_SEPARATOR, '"', '');
            foreach ($rows->export($query) as $row) {
                fputcsv($out, [$row->number, $row->jobName, $row->cityName, $statuses[$row->status] ?? $row->status, $row->dispatched, $row->activeQuotes.'/'.$row->maxQuotes, $row->riskScore, RequestSource::tryFrom($row->source)?->label() ?? $row->source, $row->submittedAt->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y H:i')], self::CSV_SEPARATOR, '"', '');
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="demandes.csv"');

        return $response;
    }

    /** BO04.2 — devis : écran livré avec le lot Devis (lot 5) ; l'onglet en indique le nombre. */
    #[Route('/devis', name: 'admin_quotes', methods: ['GET'])]
    #[IsGranted('admin.requests.view')]
    public function quotes(): Response
    {
        $page = $this->queries->ask(new AdminRequestList(perPage: 1));
        \assert($page instanceof AdminRequestPage);

        return $this->render('admin/requests/quotes.html.twig', ['page' => $page]);
    }

    /** @return array{q: string, statut: string, metier: string, departement: string, source: string, score: string} */
    private function filters(Request $request): array
    {
        $q = $request->query;

        return ['q' => trim($q->getString('q')), 'statut' => $q->getString('statut'), 'metier' => $q->getString('metier'), 'departement' => $q->getString('departement'), 'source' => $q->getString('source'), 'score' => $q->getString('score')];
    }

    /** @param array{q: string, statut: string, metier: string, departement: string, source: string, score: string} $filters */
    private function query(array $filters, int $page): AdminRequestList
    {
        return new AdminRequestList(
            $filters['q'],
            null === RequestStatus::tryFrom($filters['statut']) ? null : $filters['statut'],
            '' === $filters['metier'] ? null : $filters['metier'],
            1 === preg_match('/^(\d{2,3}|2[AB])$/', $filters['departement']) ? $filters['departement'] : null,
            null === RequestSource::tryFrom($filters['source']) ? null : $filters['source'],
            '1' === $filters['score'] ? $this->settings->int(SettingKey::ModerationRequestRiskThreshold) : null,
            $page,
            self::PER_PAGE,
        );
    }

    /** @return array<string, string> */
    private function jobs(): array
    {
        $jobs = [];
        $entries = $this->queries->ask(new JobIndexEntries());
        foreach (\is_array($entries) ? $entries : [] as $entry) {
            if ($entry instanceof JobIndexEntry) {
                $jobs[$entry->id] = $entry->name;
            }
        }
        asort($jobs);

        return $jobs;
    }

    /** @return array<string, string> */
    private function departments(): array
    {
        $departments = $this->queries->ask(new Departments());
        $list = [];
        foreach (\is_array($departments) ? $departments : [] as $code => $name) {
            $list[(string) $code] = \is_string($name) ? $name : (string) $code;
        }

        return $list;
    }
}
