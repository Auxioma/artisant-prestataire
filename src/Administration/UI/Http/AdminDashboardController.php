<?php

declare(strict_types=1);

namespace App\Administration\UI\Http;

use App\Shared\Application\Admin\PendingArtisanVerifications;
use App\Shared\Application\Bus\QueryBus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * BO01 (Figma 186:75) — socle : files d'attente disponibles à ce stade. Les indicateurs clés (BO01.1) et graphiques
 * (BO01.3) arrivent avec les données qu'ils mesurent (demandes, devis, abonnements).
 */
final class AdminDashboardController extends AbstractController
{
    #[Route('/admin', name: 'admin_dashboard', methods: ['GET'])]
    #[IsGranted('admin.dashboard.view')]
    public function __invoke(QueryBus $queries): Response
    {
        $pending = $queries->ask(new PendingArtisanVerifications());

        return $this->render('admin/dashboard.html.twig', [
            'queues' => [
                ['label' => 'Dossiers pros à vérifier', 'count' => \is_int($pending) ? $pending : 0, 'icon' => 'shield', 'permission' => 'admin.artisans.view'],
            ],
        ]);
    }
}
