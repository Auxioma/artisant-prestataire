<?php

declare(strict_types=1);

namespace App\Billing\UI\Pro;

use App\Billing\Application\Checkout\BillingDetails;
use App\Billing\Application\Checkout\CheckoutPreview;
use App\Billing\Application\Checkout\CheckoutRejected;
use App\Billing\Application\Checkout\SubscriptionCheckout;
use App\Shared\Application\Security\CurrentUser;
use App\Shared\Domain\Identifier\IdGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * PR12 (/espace-pro/abonnement/souscrire, Figma 17:2) — informations de facturation, moyen de paiement (Stripe
 * Checkout), récapitulatif, code promo et CGV ; puis PR12.4 : confirmation (« paiement en cours de confirmation » tant
 * que le webhook n'a pas activé l'abonnement — le retour navigateur ne change aucun état).
 */
final class ProSubscribeController extends AbstractController
{
    private const string CSRF = 'pro_subscribe';
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(private readonly SubscriptionCheckout $checkout, private readonly CurrentUser $user, private readonly IdGenerator $ids)
    {
    }

    #[Route('/espace-pro/abonnement/souscrire', name: 'pro_subscribe', methods: ['GET', 'POST'])]
    public function subscribe(Request $request): Response
    {
        $userId = $this->user->id() ?? throw new AccessDeniedHttpException();
        $query = ['forfait' => $request->query->getString('forfait'), 'periode' => \array_key_exists($request->query->getString('periode'), SubscriptionCheckout::PERIODS) ? $request->query->getString('periode') : 'mois'];
        if ('' !== $request->query->getString('code')) {
            $query['code'] = $request->query->getString('code');
        }
        $accessCode = $query['code'] ?? null;
        $isPost = $request->isMethod('POST');
        $input = $isPost ? $request->request->all() : [];
        $promoCode = $isPost ? trim($request->request->getString('promo')) : null;
        $errors = [];

        if ($isPost && !$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            $errors['form'] = 'La page a expiré : vérifiez vos informations puis validez à nouveau.';
        } elseif ($isPost && 'pay' === $request->request->getString('action')) {
            $attempt = $request->request->getString('attempt');
            $attempt = 1 === preg_match(self::UUID, $attempt) ? $attempt : $this->ids->next();
            try {
                $session = $this->checkout->start(
                    $userId,
                    $attempt,
                    $query['forfait'],
                    $query['periode'],
                    $accessCode,
                    $input,
                    '1' === $request->request->getString('cgv'),
                    $promoCode,
                    $this->generateUrl('pro_subscribe_return', ['id' => $attempt], UrlGeneratorInterface::ABSOLUTE_URL),
                    $this->generateUrl('pro_subscribe', $query + ['annule' => 1], UrlGeneratorInterface::ABSOLUTE_URL),
                );

                return new RedirectResponse($session->url, Response::HTTP_SEE_OTHER);
            } catch (CheckoutRejected $e) {
                if ('plan' === $e->field) {
                    $this->addFlash('error', $e->getMessage());

                    return $this->redirectToRoute('pro_subscription');
                }
                $errors[$e->field] = $e->getMessage();
            }
        }

        try {
            $preview = $this->checkout->preview($userId, $query['forfait'], $query['periode'], $accessCode);
        } catch (CheckoutRejected $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('pro_subscription');
        }
        $promotion = null;
        if (null !== $promoCode && '' !== $promoCode && !isset($errors['promo'])) {
            try {
                $promotion = $this->checkout->preview($userId, $query['forfait'], $query['periode'], $accessCode, $promoCode)->promotion;
            } catch (CheckoutRejected $e) {
                $errors['promo'] = $e->getMessage();
            }
        }

        return $this->render('pro/subscription/subscribe.html.twig', [
            'preview' => $preview,
            'query' => $query,
            'values' => $isPost ? self::values($input) : self::defaults($preview),
            'promo' => $promoCode ?? '',
            'promotion' => $promotion,
            'cgv' => $isPost && '1' === $request->request->getString('cgv'),
            'errors' => $errors,
            'attempt' => 1 === preg_match(self::UUID, $request->request->getString('attempt')) ? $request->request->getString('attempt') : $this->ids->next(),
            'cancelled' => !$isPost && $request->query->getBoolean('annule'),
            'countries' => BillingDetails::COUNTRIES,
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/espace-pro/abonnement/confirmation/{id}', name: 'pro_subscribe_return', methods: ['GET'])]
    public function confirmation(string $id): Response
    {
        $userId = $this->user->id() ?? throw new AccessDeniedHttpException();
        $subscription = $this->checkout->status($userId, $id) ?? throw new NotFoundHttpException();

        return $this->render('pro/subscription/confirmation.html.twig', [
            'subscription' => $subscription,
            'plan_name' => $this->checkout->planName($subscription->planId()),
        ]);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, string>
     */
    private static function values(array $input): array
    {
        $values = [];
        foreach (['company_name', 'street', 'postal_code', 'city', 'country', 'vat_number'] as $field) {
            $values[$field] = \is_string($input[$field] ?? null) ? $input[$field] : '';
        }

        return $values;
    }

    /** @return array<string, string> */
    private static function defaults(CheckoutPreview $preview): array
    {
        $b = $preview->billing;

        return ['company_name' => $b->companyName ?? '', 'street' => $b->street ?? '', 'postal_code' => $b->postalCode ?? '', 'city' => $b->city ?? '', 'country' => $b->country ?? 'FR', 'vat_number' => $b->vatNumber ?? ''];
    }
}
