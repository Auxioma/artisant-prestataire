<?php

declare(strict_types=1);

namespace App\Administration\UI\Http;

use App\Administration\Application\Dashboard\DashboardKpis;
use App\Administration\Application\Dashboard\DashboardKpisView;
use App\Shared\Application\Admin\PendingArtisanVerifications;
use App\Shared\Application\Admin\PendingRequestModerations;
use App\Shared\Application\Bus\QueryBus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * BO01 « Tableau de bord » (Figma 186:75) : indicateurs du mois choisi comparés à N-1 (BO01.1) et export CSV,
 * files d'attente (BO01.2). Écarts : P-139.
 */
final class AdminDashboardController extends AbstractController
{
    private const int MONTHS_OFFERED = 24;

    public function __construct(private readonly QueryBus $queries)
    {
    }

    #[Route('/admin', name: 'admin_dashboard', methods: ['GET'])]
    #[IsGranted('admin.dashboard.view')]
    public function __invoke(Request $request): Response
    {
        $pending = $this->queries->ask(new PendingArtisanVerifications());
        $moderations = $this->queries->ask(new PendingRequestModerations());
        $kpis = $this->kpis($request);

        return $this->render('admin/dashboard.html.twig', [
            'kpis' => $kpis,
            'months' => $this->months(),
            'queues' => [
                ['label' => 'Dossiers pros à vérifier', 'count' => \is_int($pending) ? $pending : 0, 'icon' => 'shield', 'permission' => 'admin.artisans.view'],
                // Figma BO01.2 « Demandes en modération » : clic → liste filtrée (BO04.1).
                ['label' => 'Demandes en modération', 'count' => \is_int($moderations) ? $moderations : 0, 'icon' => 'alert', 'permission' => 'admin.requests.view', 'route' => 'admin_requests', 'params' => ['statut' => 'pending_moderation']],
            ],
        ]);
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
