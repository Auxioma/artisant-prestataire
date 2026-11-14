<?php

declare(strict_types=1);

namespace App\Billing\Application\Checkout;

use App\Billing\Application\Payment\IdempotencyKey;
use App\Billing\Application\Payment\StripeCustomers;
use App\Billing\Application\Plan\PlanView;
use App\Billing\Application\Port\BillingAddress;
use App\Billing\Application\Port\CheckoutRequest;
use App\Billing\Application\Port\CheckoutSession;
use App\Billing\Application\Port\PaymentGateway;
use App\Billing\Application\Port\PromotionCode;
use App\Billing\Application\Port\VatNumberChecker;
use App\Billing\Application\Subscription\SubscriptionReader;
use App\Billing\Domain\Plan\BillingInterval;
use App\Billing\Domain\Plan\Plan;
use App\Billing\Domain\Plan\PlanId;
use App\Billing\Domain\Plan\PlanRepository;
use App\Billing\Domain\Plan\PlanStatus;
use App\Billing\Domain\Plan\PlanVisibility;
use App\Billing\Domain\Subscription\Subscription;
use App\Billing\Domain\Subscription\SubscriptionId;
use App\Billing\Domain\Subscription\SubscriptionRepository;
use App\Billing\Domain\Subscription\SubscriptionStatus;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Billing\ArtisanBillingIdentity;
use App\Shared\Application\Billing\ArtisanBillingIdentityDetails;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Integration\ExternalServiceUnavailable;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Application\Transaction\TransactionManager;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/**
 * PR12 — tunnel de souscription : récapitulatif, informations de facturation (VIES), code promo, puis session Stripe
 * Checkout. Rien n'est activé ici : l'abonnement est créé « en attente » et seul le webhook de paiement l'active
 * (T-604). Le prix et son identifiant Stripe sont lus en base, jamais reçus du navigateur ; une même tentative
 * (identifiant porté par le formulaire) ne crée qu'un abonnement et qu'une session (Idempotency-Key).
 */
final readonly class SubscriptionCheckout
{
    public const array PERIODS = ['mois' => BillingInterval::Month, 'an' => BillingInterval::Year];

    public function __construct(
        private SubscriptionReader $reader,
        private PlanRepository $plans,
        private SubscriptionRepository $subscriptions,
        private BillingProfiles $profiles,
        private VatNumberChecker $vies,
        private StripeCustomers $customers,
        private PaymentGateway $gateway,
        private SettingsProvider $settings,
        private TransactionManager $transactions,
        private QueryBus $queries,
        private Clock $clock,
        private AuditTrail $audit,
        private IdGenerator $ids,
    ) {
    }

    public function preview(string $userId, string $planId, string $period, ?string $accessCode = null, ?string $promoCode = null): CheckoutPreview
    {
        [$artisanId, $plan, $interval] = $this->context($userId, $planId, $period, $accessCode);
        $billing = $this->profiles->find($artisanId) ?? $this->fromProfile($userId);

        return new CheckoutPreview(
            PlanView::of($plan, 0),
            $interval,
            $plan->price($interval)->amountCents ?? 0,
            $billing,
            null === $promoCode || '' === trim($promoCode) ? null : $this->promotion($promoCode),
            $this->gateway->isConfigured(),
        );
    }

    /**
     * @param array<string, mixed> $input champs du formulaire PR12.2
     *
     * @throws CheckoutRejected
     */
    public function start(string $userId, string $attemptId, string $planId, string $period, ?string $accessCode, array $input, bool $termsAccepted, ?string $promoCode, string $successUrl, string $cancelUrl): CheckoutSession
    {
        [$artisanId, $plan, $interval] = $this->context($userId, $planId, $period, $accessCode);
        $details = BillingDetails::fromInput($input);
        if (!$termsAccepted) {
            throw new CheckoutRejected('Acceptez les conditions générales de vente professionnelles pour continuer.', 'terms');
        }
        if (!$this->gateway->isConfigured()) {
            throw new CheckoutRejected('Le paiement en ligne n’est pas encore ouvert. Réessayez un peu plus tard.');
        }
        $price = $plan->price($interval) ?? throw new CheckoutRejected('Cette formule n’est pas proposée sur cette périodicité.', 'plan');
        if (null === $price->stripePriceId) {
            throw new CheckoutRejected('Cette formule est en cours de mise en ligne : réessayez dans quelques minutes.', 'plan');
        }
        $verifiedAt = $this->verifyVat($details);
        $promotion = null === $promoCode || '' === trim($promoCode) ? null : $this->promotion($promoCode);

        try {
            $customerId = $this->customers->idFor($artisanId, $userId);
            $this->gateway->updateCustomerBilling($customerId, new BillingAddress($details->companyName, $details->street, $details->postalCode, $details->city, $details->country, $details->vatNumber), IdempotencyKey::for('UpdateBilling', $artisanId, hash('sha256', serialize($details))));
        } catch (ExternalServiceUnavailable) {
            throw new CheckoutRejected('Le service de paiement ne répond pas. Réessayez dans quelques instants.');
        }

        $now = $this->clock->now();
        $this->transactions->run(function () use ($userId, $artisanId, $attemptId, $plan, $price, $interval, $details, $verifiedAt, $now): void {
            $this->profiles->save($artisanId, $details, $verifiedAt, $now);
            // Acceptation des CGV professionnelles tracée (preuve en cas de contestation).
            $this->audit->record(new AuditRecord($this->ids->next(), $now, 'user', $userId, 'billing.checkout_started', 'subscription', $attemptId, context: ['plan_id' => $plan->id()->value, 'plan_price_id' => $price->id, 'amount_cents' => $price->amountCents, 'interval' => $interval->value, 'cgv_pro_accepted' => true]));
            $existing = $this->subscriptions->find(new SubscriptionId($attemptId));
            if (null === $existing) {
                $this->subscriptions->save(Subscription::start(new SubscriptionId($attemptId), $artisanId, $plan->id()->value, $price->id, $interval, $now));
            } elseif ($existing->artisanId() !== $artisanId || SubscriptionStatus::Incomplete !== $existing->status()) {
                throw new CheckoutRejected('Cette souscription est déjà traitée.');
            }
        });

        try {
            return $this->gateway->createSubscriptionCheckout(new CheckoutRequest(
                $customerId,
                $price->stripePriceId,
                $attemptId,
                $successUrl,
                $cancelUrl,
                'stripe_tax' === $this->settings->choice(SettingKey::BillingTaxEngine),
                $promotion?->id,
                ['subscription_id' => $attemptId, 'artisan_id' => $artisanId, 'plan_id' => $plan->id()->value, 'plan_price_id' => $price->id],
                IdempotencyKey::for('StartCheckout', $attemptId, $price->id.'|'.($promotion->id ?? '')),
            ));
        } catch (ExternalServiceUnavailable) {
            throw new CheckoutRejected('Le service de paiement ne répond pas. Réessayez dans quelques instants.');
        }
    }

    /** PR12.4 — état d'une souscription de l'artisan (confirmation de paiement en attente du webhook). */
    public function status(string $userId, string $subscriptionId): ?Subscription
    {
        $artisanId = $this->reader->artisanOfUser($userId);
        if (null === $artisanId || 1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $subscriptionId)) {
            return null;
        }
        $subscription = $this->subscriptions->find(new SubscriptionId($subscriptionId));

        return $subscription?->artisanId() === $artisanId ? $subscription : null;
    }

    public function planName(string $planId): ?string
    {
        return $this->plans->find(new PlanId($planId))?->terms()->name;
    }

    /** @return array{string, Plan, BillingInterval} */
    private function context(string $userId, string $planId, string $period, ?string $accessCode): array
    {
        $artisanId = $this->reader->artisanOfUser($userId) ?? throw new CheckoutRejected('La souscription s’ouvre une fois votre dossier professionnel vérifié.', 'plan');
        $plan = 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $planId) ? $this->plans->find(new PlanId($planId)) : null;
        if (null === $plan || PlanStatus::Published !== $plan->status()) {
            throw new CheckoutRejected('Cette formule n’est pas disponible.', 'plan');
        }
        if (PlanVisibility::Private === $plan->terms()->visibility && strtoupper(trim((string) $accessCode)) !== $plan->terms()->accessCode) {
            throw new CheckoutRejected('Cette formule est réservée : utilisez le lien fourni avec votre code d’accès.', 'plan');
        }
        $interval = self::PERIODS[$period] ?? BillingInterval::Month;
        if (null === $plan->price($interval)) {
            throw new CheckoutRejected('Cette formule n’est pas proposée sur cette périodicité.', 'plan');
        }
        if (null !== $this->subscriptions->liveOf($artisanId)) {
            throw new CheckoutRejected('Vous avez déjà un abonnement en cours : changez de formule depuis « Mes abonnements ».', 'plan');
        }

        return [$artisanId, $plan, $interval];
    }

    private function fromProfile(string $userId): ?BillingDetails
    {
        $identity = $this->queries->ask(new ArtisanBillingIdentity($userId));
        if (!$identity instanceof ArtisanBillingIdentityDetails) {
            return null;
        }
        try {
            return new BillingDetails($identity->companyName ?? '', $identity->street ?? '', $identity->postalCode ?? '', $identity->city ?? '', 'FR', $identity->vatNumber);
        } catch (CheckoutRejected) {
            return null;
        }
    }

    private function verifyVat(BillingDetails $details): ?\DateTimeImmutable
    {
        if (null === $details->vatNumber) {
            return null;
        }
        try {
            $check = $this->vies->check($details->vatNumber);
        } catch (ExternalServiceUnavailable) {
            // VIES souvent indisponible (l'adaptateur le journalise) : la souscription continue, numéro non marqué
            // vérifié ; Stripe Tax le contrôle à nouveau.
            return null;
        }
        if (!$check->valid) {
            throw new CheckoutRejected('Ce numéro de TVA n’est pas reconnu par le service européen VIES : vérifiez-le ou laissez le champ vide.', 'vat');
        }

        return $check->checkedAt;
    }

    private function promotion(string $code): PromotionCode
    {
        $code = strtoupper(trim($code));
        if (1 !== preg_match('/^[A-Z0-9_-]{2,40}$/', $code)) {
            throw new CheckoutRejected('Code promo invalide.', 'promo');
        }
        try {
            return $this->gateway->findPromotionCode($code) ?? throw new CheckoutRejected('Ce code promo n’existe pas ou n’est plus valable.', 'promo');
        } catch (ExternalServiceUnavailable) {
            throw new CheckoutRejected('Les codes promo ne peuvent pas être vérifiés pour le moment.', 'promo');
        }
    }
}
