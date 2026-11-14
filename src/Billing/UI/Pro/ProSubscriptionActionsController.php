<?php

declare(strict_types=1);

namespace App\Billing\UI\Pro;

use App\Billing\Application\Subscription\SubscriptionChangeRejected;
use App\Billing\Application\Subscription\SubscriptionChanges;
use App\Shared\Application\Security\CurrentUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * PR11.3 — changer de forfait (récapitulatif puis confirmation), passer à l'annuel, résilier (motif facultatif),
 * annuler la résiliation. Les demandes partent chez Stripe ; l'abonnement affiché se met à jour par webhook.
 */
final class ProSubscriptionActionsController extends AbstractController
{
    private const string CSRF = 'pro_subscription_action';

    public function __construct(private readonly SubscriptionChanges $changes, private readonly CurrentUser $user)
    {
    }

    #[Route('/espace-pro/abonnement/changer', name: 'pro_subscription_change', methods: ['GET', 'POST'])]
    public function change(Request $request): Response
    {
        $userId = $this->userId();
        $plan = $request->query->getString('forfait');
        $period = \array_key_exists($request->query->getString('periode'), SubscriptionChanges::PERIODS) ? $request->query->getString('periode') : 'mois';
        try {
            if ($request->isMethod('POST')) {
                $this->guardCsrf($request);
                $immediate = $this->changes->change($userId, $plan, $period);
                $this->addFlash('success', $immediate
                    ? 'Changement de formule transmis : il s’applique dès la confirmation du paiement au prorata.'
                    : 'Changement de formule programmé : il prendra effet à votre prochaine échéance.');

                return $this->redirectToRoute('pro_subscription', [], Response::HTTP_SEE_OTHER);
            }

            return $this->render('pro/subscription/change.html.twig', ['preview' => $this->changes->preview($userId, $plan, $period), 'query' => ['forfait' => $plan, 'periode' => $period]]);
        } catch (SubscriptionChangeRejected $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('pro_subscription', [], Response::HTTP_SEE_OTHER);
        }
    }

    #[Route('/espace-pro/abonnement/resilier', name: 'pro_subscription_cancel', methods: ['GET', 'POST'])]
    public function cancel(Request $request): Response
    {
        $userId = $this->userId();
        if ($request->isMethod('GET')) {
            return $this->render('pro/subscription/cancel.html.twig', ['reasons' => SubscriptionChanges::LEAVE_REASONS]);
        }
        try {
            $this->guardCsrf($request);
            $reason = $request->request->getString('motif');
            $this->changes->cancel($userId, '' === $reason ? null : $reason);
            $this->addFlash('success', 'Résiliation programmée : votre forfait reste actif jusqu’à la fin de la période payée.');
        } catch (SubscriptionChangeRejected $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('pro_subscription', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/espace-pro/abonnement/reprendre', name: 'pro_subscription_resume', methods: ['POST'])]
    public function resume(Request $request): Response
    {
        $userId = $this->userId();
        try {
            $this->guardCsrf($request);
            $this->changes->resume($userId);
            $this->addFlash('success', 'Résiliation annulée : votre abonnement continue.');
        } catch (SubscriptionChangeRejected $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('pro_subscription', [], Response::HTTP_SEE_OTHER);
    }

    private function userId(): string
    {
        return $this->user->id() ?? throw new AccessDeniedHttpException();
    }

    private function guardCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            throw new SubscriptionChangeRejected('La page a expiré : recommencez.');
        }
    }
}
