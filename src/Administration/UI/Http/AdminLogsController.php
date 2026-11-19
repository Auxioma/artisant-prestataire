<?php

declare(strict_types=1);

namespace App\Administration\UI\Http;

use App\Administration\Application\Logs\AuditLogEntry;
use App\Administration\Application\Logs\AuditLogFilter;
use App\Administration\Application\Logs\AuditLogPage;
use App\Administration\Application\Logs\AuditLogQuery;
use App\Shared\Application\Admin\StripeWebhookLog;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentAdministrator;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * BO15.1 « Finance, journaux et exports » (/admin/logs, Figma 186:6608) — journal d'audit des actions admin et
 * connexions (filtres, IP masquée, export CSV lui-même journalisé), webhooks Stripe reçus. Écarts : P-142.
 */
#[Route('/admin/logs')]
final class AdminLogsController extends AbstractController
{
    public const array TABS = ['finance' => 'Finance', 'audit' => 'Journal d’audit', 'connexions' => 'Connexions', 'webhooks' => 'Webhooks Stripe', 'exports' => 'Exports'];
    /** Onglets à venir (T-806b finance, T-806c exports). */
    public const array SOON = ['finance', 'exports'];
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
    public function index(Request $request): Response
    {
        $tab = $request->query->getString('onglet', 'audit');
        $tab = \array_key_exists($tab, self::TABS) && !\in_array($tab, self::SOON, true) ? $tab : 'audit';
        $page = max(1, $request->query->getInt('page', 1));
        $data = ['tab' => $tab, 'tabs' => self::TABS, 'soon' => self::SOON, 'periods' => AuditLogFilter::PERIODS, 'labels' => self::LABELS];

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
    public function export(Request $request, AuditTrail $audit, IdGenerator $ids, Clock $clock, CurrentAdministrator $admin): StreamedResponse
    {
        $tab = AuditLogFilter::VIEW_LOGINS === $request->query->getString('onglet') ? AuditLogFilter::VIEW_LOGINS : AuditLogFilter::VIEW_AUDIT;
        $filter = $this->filter($request, $tab);
        $log = $this->queries->ask(new AuditLogQuery($filter, 1, self::EXPORT_MAX));
        \assert($log instanceof AuditLogPage);
        // BO15.2 : un export de journal contient des données personnelles (noms, IP masquées) → il est lui-même journalisé.
        $audit->record(new AuditRecord(
            id: $ids->next(),
            occurredAt: $clock->now(),
            actorType: 'admin',
            actorId: $admin->id(),
            action: 'admin.export',
            subjectType: 'audit_log',
            subjectId: $tab,
            ip: $request->getClientIp(),
            context: ['filters' => http_build_query($filter->toArray()), 'rows' => \count($log->rows)],
        ));
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
