<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Payment;

use App\Billing\Application\Port\BillingAddress;
use App\Billing\Application\Port\CheckoutRequest;
use App\Billing\Application\Port\CheckoutSession;
use App\Billing\Application\Port\PaymentGateway;
use App\Billing\Application\Port\PaymentMethodSummary;
use App\Billing\Application\Port\PromotionCode;
use App\Billing\Application\Port\PromotionSpec;
use App\Billing\Application\Port\SubscriptionState;
use App\Billing\Domain\Plan\BillingInterval;
use App\Shared\Application\Integration\ExternalServiceUnavailable;

/**
 * Double de test du prestataire (environnement test) : identifiants déterministes, mêmes garanties d'idempotence que
 * Stripe (une clé déjà vue renvoie la première réponse), journal des appels pour les assertions.
 */
final class InMemoryPaymentGateway implements PaymentGateway
{
    /** @var array<string, string> clé d'idempotence → identifiant renvoyé */
    private array $responses = [];
    /** @var list<array{string, array<string, mixed>}> */
    public array $calls = [];
    public bool $configured = true;
    public bool $failing = false;

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function mode(): ?string
    {
        return $this->configured ? 'test' : null;
    }

    public function createCustomer(string $email, string $name, array $metadata, string $idempotencyKey): string
    {
        return $this->respond('cus', $idempotencyKey, ['email' => $email, 'name' => $name, 'metadata' => $metadata]);
    }

    public function saveProduct(?string $productId, string $name, ?string $description, bool $active, array $metadata, string $idempotencyKey): string
    {
        return $this->respond('prod', $idempotencyKey, ['id' => $productId, 'name' => $name, 'active' => $active, 'metadata' => $metadata], $productId);
    }

    public function createPrice(string $productId, int $amountCents, BillingInterval $interval, array $metadata, string $idempotencyKey): string
    {
        return $this->respond('price', $idempotencyKey, ['product' => $productId, 'amount' => $amountCents, 'interval' => $interval->value, 'metadata' => $metadata]);
    }

    public function deactivatePrice(string $priceId, string $idempotencyKey): void
    {
        $this->respond('price_off', $idempotencyKey, ['id' => $priceId], $priceId);
    }

    /** @var array<string, PromotionCode> codes promo actifs (saisie en majuscules → code) */
    public array $promotionCodes = [];

    public function updateCustomerBilling(string $customerId, BillingAddress $address, string $idempotencyKey): void
    {
        $this->respond('customer_update', $idempotencyKey, ['id' => $customerId, 'name' => $address->name, 'country' => $address->country, 'vat' => $address->vatNumber], $customerId);
    }

    public function createPromotion(PromotionSpec $spec): array
    {
        if (!$this->configured) {
            throw new ExternalServiceUnavailable('stripe', 'clé secrète non configurée');
        }
        $coupon = $this->respond('coupon', $spec->idempotencyKey.'-coupon', ['code' => $spec->code, 'percent' => $spec->percentOff, 'amount' => $spec->amountOffCents, 'duration' => $spec->duration, 'months' => $spec->durationMonths, 'products' => $spec->productIds]);
        $promotion = $this->respond('promo', $spec->idempotencyKey.'-promotion', ['coupon' => $coupon, 'code' => $spec->code, 'max' => $spec->maxRedemptions, 'first_time' => $spec->firstTimeOnly]);

        return ['coupon' => $coupon, 'promotion' => $promotion];
    }

    public function deactivatePromotionCode(string $promotionCodeId, string $idempotencyKey): void
    {
        $this->respond('promo_off', $idempotencyKey, ['id' => $promotionCodeId], $promotionCodeId);
    }

    public function findPromotionCode(string $code): ?PromotionCode
    {
        if (!$this->configured) {
            throw new ExternalServiceUnavailable('stripe', 'clé secrète non configurée');
        }

        return $this->promotionCodes[strtoupper($code)] ?? null;
    }

    public function createSubscriptionCheckout(CheckoutRequest $request): CheckoutSession
    {
        if (!$this->configured) {
            throw new ExternalServiceUnavailable('stripe', 'clé secrète non configurée');
        }
        $id = $this->respond('checkout', $request->idempotencyKey, ['customer' => $request->customerId, 'price' => $request->priceId, 'reference' => $request->clientReference, 'tax' => $request->automaticTax, 'promotion' => $request->promotionCodeId, 'metadata' => $request->metadata, 'success' => $request->successUrl]);

        return new CheckoutSession($id, 'https://checkout.stripe.test/'.$id);
    }

    /** @var array<string, SubscriptionState> abonnements Stripe simulés, par identifiant */
    public array $subscriptions = [];

    public function retrieveSubscription(string $subscriptionId): SubscriptionState
    {
        if ($this->failing) {
            throw new ExternalServiceUnavailable('stripe', 'panne simulée');
        }

        return $this->subscriptions[$subscriptionId] ?? throw new ExternalServiceUnavailable('stripe', 'abonnement inconnu');
    }

    public function cancelSubscription(string $subscriptionId, string $idempotencyKey): void
    {
        $this->respond('subscription_cancel', $idempotencyKey, ['id' => $subscriptionId], $subscriptionId);
    }

    public function changeSubscriptionPrice(string $subscriptionId, string $priceId, string $prorationBehavior, string $idempotencyKey): void
    {
        $this->respond('subscription_price', $idempotencyKey, ['id' => $subscriptionId, 'price' => $priceId, 'proration' => $prorationBehavior], $subscriptionId);
    }

    public function scheduleSubscriptionPrice(string $subscriptionId, string $priceId, string $idempotencyKey): void
    {
        $this->respond('subscription_schedule', $idempotencyKey, ['id' => $subscriptionId, 'price' => $priceId], $subscriptionId);
    }

    public function setCancelAtPeriodEnd(string $subscriptionId, bool $cancel, string $idempotencyKey): void
    {
        $this->respond($cancel ? 'subscription_cancel_at_end' : 'subscription_resume', $idempotencyKey, ['id' => $subscriptionId], $subscriptionId);
    }

    /** @var array<string, PaymentMethodSummary> moyen par défaut, par client */
    public array $paymentMethods = [];
    /** @var array<string, string> PDF de facture servis, par URL */
    public array $pdfs = [];

    public function defaultPaymentMethod(string $customerId): ?PaymentMethodSummary
    {
        return $this->paymentMethods[$customerId] ?? null;
    }

    public function createPortalSession(string $customerId, string $returnUrl): string
    {
        $this->calls[] = ['portal', ['customer' => $customerId, 'return' => $returnUrl]];

        return 'https://billing.stripe.test/session/'.substr(hash('sha256', $customerId), 0, 12);
    }

    public function downloadInvoicePdf(string $url): string
    {
        return $this->pdfs[$url] ?? throw new ExternalServiceUnavailable('stripe', 'PDF indisponible');
    }

    public function reset(): void
    {
        [$this->responses, $this->calls, $this->configured, $this->failing, $this->promotionCodes, $this->subscriptions, $this->paymentMethods, $this->pdfs] = [[], [], true, false, [], [], [], []];
    }

    /** @param array<string, mixed> $params */
    private function respond(string $kind, string $key, array $params, ?string $id = null): string
    {
        if ($this->failing) {
            throw new ExternalServiceUnavailable('stripe', 'panne simulée');
        }
        if (isset($this->responses[$key])) {
            return $this->responses[$key];
        }
        $this->calls[] = [$kind, $params];

        return $this->responses[$key] = $id ?? $kind.'_'.substr(hash('sha256', $key), 0, 14);
    }
}
