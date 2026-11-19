<?php

declare(strict_types=1);

namespace App\Administration\UI\Http;

use App\Administration\Application\Dashboard\DashboardActivity;
use App\Administration\Application\Dashboard\DashboardActivityView;
use App\Administration\Application\Dashboard\DashboardKpis;
use App\Administration\Application\Dashboard\DashboardKpisView;
use App\Administration\Application\Dashboard\DashboardQueues;
use App\Shared\Application\Bus\QueryBus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * BO01 « Tableau de bord » (Figma 186:75) : indicateurs du mois choisi comparés à N-1 (BO01.1) et export CSV,
 * files d'attente avec lien vers la liste filtrée (BO01.2), évolution quotidienne des demandes et devis (BO01.3).
 * Écarts : P-139, P-140.
 */
final class AdminDashboardController extends AbstractController
{
    private const int MONTHS_OFFERED = 24;
    /**
     * BO01.2 — files de la maquette, dans l'ordre : libellé, icône, droit requis, liste filtrée (route, paramètres).
     * Sans route : pas encore de liste dédiée ; sans compteur (null) : module à venir (« Bientôt »).
     */
    private const array QUEUES = [
        'artisans_pending' => ['Dossiers pros à vérifier', 'shield', 'admin.artisans.view', 'admin_artisans_queue', []],
        'documents_pending' => ['Justificatifs à contrôler', 'file', 'admin.artisans.view', 'admin_artisans', ['statut' => 'documents']],
        'reviews_pending' => ['Avis à modérer', 'star', 'admin.moderation.view', 'admin_moderation_tab', ['onglet' => 'avis']],
        'reports_open' => ['Signalements', 'alert', 'admin.moderation.view', 'admin_moderation_tab', ['onglet' => 'signalements']],
        'requests_moderation' => ['Demandes en modération', 'file', 'admin.requests.view', 'admin_requests', ['statut' => 'pending_moderation']],
        'support_tickets' => ['Tickets support', 'msg', 'admin.support.view', null, []],
        'payments_failed' => ['Paiements échoués', 'alert', 'admin.plans.view', null, []],
    ];

    public function __construct(private readonly QueryBus $queries)
    {
    }

    #[Route('/admin', name: 'admin_dashboard', methods: ['GET'])]
    #[IsGranted('admin.dashboard.view')]
    public function __invoke(Request $request): Response
    {
        $kpis = $this->kpis($request);
        $counts = $this->queries->ask(new DashboardQueues());
        $counts = \is_array($counts) ? $counts : [];
        $activity = $this->queries->ask(new DashboardActivity($kpis->from, $kpis->to));
        \assert($activity instanceof DashboardActivityView);
        $queues = [];
        foreach (self::QUEUES as $key => [$label, $icon, $permission, $route, $params]) {
            if ($this->isGranted($permission)) {
                $count = $counts[$key] ?? null;
                $queues[] = ['key' => $key, 'label' => $label, 'icon' => $icon, 'count' => \is_int($count) ? $count : null, 'href' => null === $route ? null : $this->generateUrl($route, $params)];
            }
        }

        return $this->render('admin/dashboard.html.twig', ['kpis' => $kpis, 'months' => $this->months(), 'queues' => $queues, 'activity' => $activity]);
    }

    #[Route('/admin/tableau-de-bord.csv', name: 'admin_dashboard_export', methods: ['GET'])]
    #[IsGranted('admin.dashboard.export')]
    public function export(Request $request): StreamedResponse
    {
        $kpis = $this->kpis($request);
        $response = new StreamedResponse(static function () use ($kpis): void {
            $out = fopen('php://output', 'w');
            \assert(\is_resource($out));
            fputcsv($out, ['indicateur', 'cle', 'unite', 'periode', 'valeur', 'valeur_n_1', 'ecart', 'unite_ecart'], ';', '"', '');
            $period = $kpis->from->format('Y-m-d').' / '.$kpis->to->format('Y-m-d H:i');
            foreach ($kpis->all() as $card) {
                fputcsv($out, [$card->label(), $card->key(), $card->unit(), $period, self::raw($card->value), self::raw($card->previous), self::raw($card->delta), $card->deltaUnit], ';', '"', '');
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="tableau-de-bord-'.$kpis->month.'.csv"');

        return $response;
    }

    private function kpis(Request $request): DashboardKpisView
    {
        $month = $request->query->getString('mois');
        $view = $this->queries->ask(new DashboardKpis('' === $month ? null : $month, new \DateTimeImmutable()));
        \assert($view instanceof DashboardKpisView);

        return $view;
    }

    /** @return list<string> mois proposés, du plus récent au plus ancien (« 2026-10 ») */
    private function months(): array
    {
        $month = new \DateTimeImmutable('first day of this month');
        $months = [];
        for ($i = 0; $i < self::MONTHS_OFFERED; ++$i) {
            $months[] = $month->modify(\sprintf('-%d months', $i))->format('Y-m');
        }

        return $months;
    }

    private static function raw(int|float|null $value): string
    {
        return null === $value ? '' : (\is_float($value) ? (string) round($value, 4) : (string) $value);
    }
}
