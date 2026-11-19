<?php

declare(strict_types=1);

namespace App\Administration\UI\Http;

use App\Administration\Application\Dashboard\DashboardKpis;
use App\Administration\Application\Dashboard\DashboardKpisView;
use App\Administration\Application\Dashboard\DashboardMetric;
use App\Administration\Application\Logs\AuditLogEntry;
use App\Administration\Application\Logs\AuditLogFilter;
use App\Administration\Application\Logs\AuditLogPage;
use App\Administration\Application\Logs\AuditLogQuery;
use App\Shared\Application\Admin\AdminExportJournal;
use App\Shared\Application\Admin\FinanceBreakdown;
use App\Shared\Application\Admin\FinanceBreakdownView;
use App\Shared\Application\Admin\StripeWebhookLog;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Domain\Time\Clock;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * BO15 « Finance, journaux et exports » (/admin/logs, Figma 186:6608) — finance du mois comparée à N-1, MRR par
 * forfait, cohortes, rapprochement (BO15.3) ; journal d'audit des actions admin et connexions (filtres, IP masquée,
 * export CSV lui-même journalisé), webhooks Stripe reçus (BO15.1). Écarts : P-142, P-143.
 */
#[Route('/admin/logs')]
final class AdminLogsController extends AbstractController
{
    public const array TABS = ['finance' => 'Finance', 'audit' => 'Journal d’audit', 'connexions' => 'Connexions', 'webhooks' => 'Webhooks Stripe', 'exports' => 'Exports'];
    /**
     * BO15.2 — exports disponibles : libellé, route et paramètres, droit requis, données personnelles (→ journalisé).
     * Depuis cet onglet l'export porte sur toute la liste ; depuis la liste, il reprend ses filtres.
     */
    private const array EXPORTS = [
        'clients' => ['Clients', 'admin_clients_export', [], 'admin.clients.export', true],
        'artisans' => ['Artisans', 'admin_artisans_export', [], 'admin.artisans.export', true],
        'journal_audit' => ['Journal d’audit (actions admin)', 'admin_logs_export', ['onglet' => 'audit'], 'admin.logs.export', true],
        'journal_connexions' => ['Connexions', 'admin_logs_export', ['onglet' => 'connexions'], 'admin.logs.export', true],
        'journal_envois' => ['Journal d’envoi des e-mails', 'admin_notifications_log_export', [], 'admin.notifications.view', true],
        'demandes' => ['Demandes', 'admin_requests_export', [], 'admin.requests.export', false],
        'codes_promo' => ['Codes promo', 'admin_promo_export', [], 'admin.plans.export', false],
        'redirections' => ['Redirections (référencement)', 'admin_seo_redirect_export', [], 'admin.seo.view', false],
        'tableau_de_bord' => ['Tableau de bord du mois', 'admin_dashboard_export', [], 'admin.dashboard.export', false],
        'finance' => ['Finance du mois', 'admin_logs_finance_export', [], 'admin.logs.export', false],
    ];
    private const int RECENT_EXPORTS = 20;
    private const int MONTHS_OFFERED = 24;
    private const int PER_PAGE = 25;
    private const int EXPORT_MAX = 50000;
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    /** Libellés lisibles des actions les plus fréquentes ; les autres s'affichent sous leur code. */
    private const array LABELS = [
        'admin.login' => 'Connexion au back-office', 'admin.login_failed' => 'Échec de connexion au back-office', 'admin.backup_code_used' => 'Code de secours utilisé',
        'admin.invited' => 'Administrateur invité', 'admin.activated' => 'Compte administrateur activé', 'admin.export' => 'Export CSV',
        'identity.login' => 'Connexion', 'security.login_failed' => 'Échec de connexion',
        'administration.setting_changed' => 'Paramètre modifié', 'file.read' => 'Lecture d’un fichier', 'file.shredded' => 'Fichier détruit',
        'artisan.approved' => 'Dossier artisan validé', 'artisan.rejected' => 'Dossier artisan refusé', 'artisan.suspended' => 'Artisan suspendu',
        'review.published' => 'Avis publié', 'review.rejected' => 'Avis refusé', 'request.moderation_reject' => 'Demande refusée en modération',
        'request.redispatched' => 'Demande re-diffusée', 'request.dispatch_extended' => 'Zone de diffusion étendue',
        'billing.refund' => 'Remboursement', 'billing.free_credit_granted' => 'Crédits gratuits attribués',
    ];

    public function __construct(private readonly QueryBus $queries)
    {
    }

    #[Route('', name: 'admin_logs', methods: ['GET'])]
    #[IsGranted('admin.logs.view')]
    public function index(Request $request, Clock $clock): Response
    {
        $tab = $request->query->getString('onglet', 'finance');
        $tab = \array_key_exists($tab, self::TABS) ? $tab : 'finance';
        $page = max(1, $request->query->getInt('page', 1));
        $data = ['tab' => $tab, 'tabs' => self::TABS, 'periods' => AuditLogFilter::PERIODS, 'labels' => self::LABELS];

        if ('finance' === $tab) {
            [$kpis, $breakdown] = $this->finances($request, $clock);
            $conversion = array_values(array_filter($kpis->featured, static fn ($c): bool => DashboardMetric::FreeToPaidRate === $c->metric))[0] ?? null;

            return $this->render('admin/logs/index.html.twig', $data + ['kpis' => $kpis, 'breakdown' => $breakdown, 'conversion' => $conversion, 'months' => $this->months($clock)]);
        }

        if ('exports' === $tab) {
            $exports = [];
            foreach (self::EXPORTS as $key => [$label, $route, $params, $permission, $personal]) {
                if ($this->isGranted($permission)) {
                    $exports[] = ['key' => $key, 'label' => $label, 'href' => $this->generateUrl($route, $params), 'personal' => $personal];
                }
            }
            $recent = $this->queries->ask(new AuditLogQuery(new AuditLogFilter(AuditLogFilter::VIEW_AUDIT, AdminExportJournal::ACTION), 1, self::RECENT_EXPORTS));
            \assert($recent instanceof AuditLogPage);

            $filters = array_map(static function (AuditLogEntry $e): string {
                parse_str(\is_string($e->context['filters'] ?? null) ? $e->context['filters'] : '', $parsed);
                $parts = [];
                foreach ($parsed as $name => $value) {
                    $parts[] = $name.' : '.(\is_string($value) ? $value : '…');
                }

                return [] === $parts ? 'aucun' : implode(' · ', $parts);
            }, $recent->rows);

            return $this->render('admin/logs/index.html.twig', $data + ['exports' => $exports, 'recent' => $recent, 'recent_filters' => $filters, 'export_labels' => array_map(static fn (array $e): string => $e[0], self::EXPORTS)]);
        }

        if ('webhooks' === $tab) {
            $status = $request->query->getString('statut');
            $log = $this->queries->ask(new StripeWebhookLog('' === $status ? null : $status, ($page - 1) * self::PER_PAGE, self::PER_PAGE));
            $log = \is_array($log) ? $log : ['total' => 0, 'rows' => []];

            return $this->render('admin/logs/index.html.twig', $data + ['webhooks' => $log, 'status' => $status, 'page' => $page, 'per_page' => self::PER_PAGE]);
        }

        $filter = $this->filter($request, $tab);
        $log = $this->queries->ask(new AuditLogQuery($filter, $page, self::PER_PAGE));
        \assert($log instanceof AuditLogPage);

        return $this->render('admin/logs/index.html.twig', $data + ['log' => $log, 'filter' => $filter, 'links' => array_map($this->link(...), $log->rows)]);
    }

    #[Route('/export.csv', name: 'admin_logs_export', methods: ['GET'])]
    #[IsGranted('admin.logs.export')]
    public function export(Request $request, AdminExportJournal $journal, Clock $clock): StreamedResponse
    {
        $tab = AuditLogFilter::VIEW_LOGINS === $request->query->getString('onglet') ? AuditLogFilter::VIEW_LOGINS : AuditLogFilter::VIEW_AUDIT;
        $filter = $this->filter($request, $tab);
        $log = $this->queries->ask(new AuditLogQuery($filter, 1, self::EXPORT_MAX));
        \assert($log instanceof AuditLogPage);
        // BO15.2 : un export de journal contient des données personnelles (noms, IP masquées) → il est lui-même journalisé.
        $journal->record('journal_'.$tab, $filter->toArray(), \count($log->rows), $request->getClientIp());
        $rows = $log->rows;
        $response = new StreamedResponse(static function () use ($rows): void {
            $out = fopen('php://output', 'w');
            \assert(\is_resource($out));
            fputcsv($out, ['date', 'auteur', 'type_auteur', 'action', 'objet', 'id_objet', 'ip_masquee', 'en_impersonation'], ';', '"', '');
            foreach ($rows as $r) {
                fputcsv($out, [$r->occurredAt->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y-m-d H:i:s'), $r->actorName ?? '', $r->actorType, $r->action, $r->subjectType ?? '', $r->subjectId ?? '', $r->maskedIp ?? '', $r->impersonated ? 'oui' : 'non'], ';', '"', '');
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="journal-'.$tab.'-'.$clock->now()->format('Y-m-d').'.csv"');

        return $response;
    }

    #[Route('/finance.csv', name: 'admin_logs_finance_export', methods: ['GET'])]
    #[IsGranted('admin.logs.export')]
    public function financeExport(Request $request, Clock $clock): StreamedResponse
    {
        [$kpis, $breakdown] = $this->finances($request, $clock);
        $response = new StreamedResponse(static function () use ($kpis, $breakdown): void {
            $out = fopen('php://output', 'w');
            \assert(\is_resource($out));
            fputcsv($out, ['rubrique', 'libelle', 'valeur', 'valeur_n_1', 'ecart', 'unite_ecart'], ';', '"', '');
            foreach ($kpis->finance as $card) {
                fputcsv($out, ['indicateur', $card->label(), (string) ($card->value ?? ''), (string) ($card->previous ?? ''), null === $card->delta ? '' : (string) round($card->delta, 2), $card->deltaUnit], ';', '"', '');
            }
            foreach ($breakdown->plans as $plan) {
                fputcsv($out, ['mrr_par_forfait', $plan['label'], (string) $plan['mrr_cents'], '', '', ''], ';', '"', '');
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="finance-'.$kpis->month.'.csv"');

        return $response;
    }

    /** @return array{0: DashboardKpisView, 1: FinanceBreakdownView} */
    private function finances(Request $request, Clock $clock): array
    {
        $month = $request->query->getString('mois');
        $kpis = $this->queries->ask(new DashboardKpis('' === $month ? null : $month, $clock->now()));
        \assert($kpis instanceof DashboardKpisView);
        $breakdown = $this->queries->ask(new FinanceBreakdown($kpis->from, $kpis->to, $clock->now()));
        \assert($breakdown instanceof FinanceBreakdownView);

        return [$kpis, $breakdown];
    }

    /** @return list<string> */
    private function months(Clock $clock): array
    {
        $month = $clock->now()->setTimezone(new \DateTimeZone('Europe/Paris'))->modify('first day of this month');
        $months = [];
        for ($i = 0; $i < self::MONTHS_OFFERED; ++$i) {
            $months[] = $month->modify(\sprintf('-%d months', $i))->format('Y-m');
        }

        return $months;
    }

    private function filter(Request $request, string $tab): AuditLogFilter
    {
        $module = $request->query->getString('module');
        $adminId = $request->query->getString('admin');
        $days = $request->query->getInt('periode');

        return new AuditLogFilter(
            AuditLogFilter::VIEW_LOGINS === $tab ? AuditLogFilter::VIEW_LOGINS : AuditLogFilter::VIEW_AUDIT,
            mb_substr(trim($request->query->getString('q')), 0, 100),
            1 === preg_match('/^[a-z_]{1,40}$/', $module) ? $module : null,
            1 === preg_match(self::UUID, $adminId) ? $adminId : null,
            \array_key_exists($days, AuditLogFilter::PERIODS) ? $days : null,
        );
    }

    /** Lien vers l'objet de l'action quand un écran du back-office l'affiche. */
    private function link(AuditLogEntry $entry): ?string
    {
        return match ($entry->subjectType) {
            'artisan_profile' => 1 === preg_match(self::UUID, (string) $entry->subjectId) ? $this->generateUrl('admin_artisans_show', ['id' => $entry->subjectId]) : null,
            'admin_user' => $this->generateUrl('admin_team'),
            'setting' => $this->generateUrl('admin_settings'),
            'quote_request' => $this->generateUrl('admin_requests', ['q' => $entry->subjectId]),
            default => null,
        };
    }
}
