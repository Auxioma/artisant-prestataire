<?php

declare(strict_types=1);

namespace App\Billing\UI\Pro;

use App\Billing\Application\Subscription\ProSubscription;
use App\Billing\Application\Subscription\ProSubscriptionView;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * PR11 (/espace-pro/abonnement, Figma 167:1401) — « Mes abonnements » : situation actuelle (PR11.1), options du
 * forfait, grille des formules avec le forfait actuel (PR11.4), factures et moyen de paiement (T-605), historique des
 * crédits (PR11.2, onglet « Historique »).
 */
final class ProSubscriptionController extends AbstractController
{
    public const array TABS = ['abonnement', 'factures', 'historique'];

    public function __construct(private readonly QueryBus $queries, private readonly CurrentUser $user)
    {
    }

    #[Route('/espace-pro/abonnement', name: 'pro_subscription', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $userId = $this->user->id() ?? throw new AccessDeniedHttpException();
        $tab = \in_array($request->query->getString('onglet'), self::TABS, true) ? $request->query->getString('onglet') : 'abonnement';
        $view = $this->queries->ask(new ProSubscription($userId));

        return $this->render('pro/subscription/index.html.twig', [
            'view' => $view instanceof ProSubscriptionView ? $view : null,
            'tab' => $tab,
        ]);
    }
}
