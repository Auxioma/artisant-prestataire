<?php

declare(strict_types=1);

namespace App\Quote\UI\Http;

use App\Quote\Application\Pro\ProDashboard;
use App\Quote\Application\Pro\ProDashboardView;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * PR01 (/espace-pro, Figma 22:150) — tableau de bord de l'artisan : indicateurs, dernières demandes, crédit de devis
 * (PR01.1, RG-105/106), activité 30 jours / 12 mois, profil, accès rapides. Page d'arrivée après connexion.
 */
final class ProDashboardController extends AbstractController
{
    public function __construct(private readonly QueryBus $queries, private readonly CurrentUser $user)
    {
    }

    #[Route('/espace-pro', name: 'pro_dashboard', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $userId = $this->user->id() ?? throw new AccessDeniedHttpException();
        $days = ProDashboard::PERIODS[$request->query->getString('periode')] ?? ProDashboard::PERIODS['30'];
        $view = $this->queries->ask(new ProDashboard($userId, $days));

        return $this->render('pro/dashboard.html.twig', [
            'view' => $view instanceof ProDashboardView ? $view : null,
            'periods' => ['30' => '30 derniers jours', '365' => '12 derniers mois'],
        ]);
    }
}
