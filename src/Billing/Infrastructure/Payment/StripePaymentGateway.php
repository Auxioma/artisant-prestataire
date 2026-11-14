<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Payment;

use App\Billing\Application\Port\BillingAddress;
use App\Billing\Application\Port\CheckoutRequest;
use App\Billing\Application\Port\CheckoutSession;
use App\Billing\Application\Port\PaymentGateway;
use App\Billing\Application\Port\PromotionCode;
use App\Billing\Application\Port\SubscriptionState;
use App\Billing\Domain\Plan\BillingInterval;
use App\Shared\Application\Integration\ExternalServiceUnavailable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Adaptateur stripe-php. Clé secrète lue dans le coffre de secrets en production, dans `.env.dev.local` en
 * développement ; jamais en base ni dans les journaux. Version d'API figée (une montée de version est un changement
 * délibéré), reprises confiées au worker (Messenger), chaque écriture porte l'Idempotency-Key fournie.
 */
final class StripePaymentGateway implements PaymentGateway
{
    private const string SERVICE = 'stripe';
    private const string API_VERSION = '2025-08-27.basil';
    private const string CURRENCY = 'eur';

    private ?StripeClient $client = null;

    public function __construct(
        #[Target('stripe.client')]
        private readonly HttpClientInterface $http,
        #[Autowire('%env(default::STRIPE_SECRET_KEY)%')]
        private readonly ?string $secretKey,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function isConfigured(): bool
    {
        return null !== $this->secretKey && '' !== trim($this->secretKey);
    }

    public function mode(): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }

        return str_contains((string) $this->secretKey, '_live_') ? 'live' : 'test';
    }

    public function createCustomer(string $email, string $name, array $metadata, string $idempotencyKey): string
    {
        $params = ['email' => $email, 'metadata' => $metadata, 'preferred_locales' => ['fr']];
        if ('' !== $name) {
            $params['name'] = $name;
        }

        return $this->call('customer', static fn (StripeClient $c): string => $c->customers->create(
            $params,
            ['idempotency_key' => $idempotencyKey],
        )->id);
    }

    public function saveProduct(?string $productId, string $name, ?string $description, bool $active, array $metadata, string $idempotencyKey): string
    {
        $params = ['name' => $name, 'description' => $description ?? '', 'active' => $active, 'metadata' => $metadata];
        if (null === $productId) {
            // Abonnement à un service numérique : code de taxe Stripe des SaaS (B2B, ADR-0009).
            return $this->call('product', static fn (StripeClient $c): string => $c->products->create(
                $params + ['tax_code' => 'txcd_10103001'],
                ['idempotency_key' => $idempotencyKey],
            )->id);
        }

        return $this->call('product', static fn (StripeClient $c): string => $c->products->update($productId, $params, ['idempotency_key' => $idempotencyKey])->id);
    }

    public function createPrice(string $productId, int $amountCents, BillingInterval $interval, array $metadata, string $idempotencyKey): string
    {
        return $this->call('price', static fn (StripeClient $c): string => $c->prices->create([
            'product' => $productId,
            'currency' => self::CURRENCY,
            'unit_amount' => $amountCents,
            'tax_behavior' => 'exclusive',
            'recurring' => ['interval' => $interval->value],
            'metadata' => $metadata,
        ], ['idempotency_key' => $idempotencyKey])->id);
    }

    public function deactivatePrice(string $priceId, string $idempotencyKey): void
    {
        $this->call('price', static fn (StripeClient $c): string => $c->prices->update($priceId, ['active' => false], ['idempotency_key' => $idempotencyKey])->id);
    }

    public function updateCustomerBilling(string $customerId, BillingAddress $address, string $idempotencyKey): void
    {
        $this->call('customer', static function (StripeClient $c) use ($customerId, $address, $idempotencyKey): string {
            $c->customers->update($customerId, [
                'name' => $address->name,
                'address' => ['line1' => $address->street, 'postal_code' => $address->postalCode, 'city' => $address->city, 'country' => $address->country],
            ], ['idempotency_key' => $idempotencyKey]);
            if (null !== $address->vatNumber) {
                $known = array_map(static fn ($t): string => (string) $t->value, $c->customers->allTaxIds($customerId, ['limit' => 20])->data);
                if (!\in_array($address->vatNumber, $known, true)) {
                    $c->customers->createTaxId($customerId, ['type' => 'eu_vat', 'value' => $address->vatNumber], ['idempotency_key' => $idempotencyKey.'-tva']);
                }
            }

            return $customerId;
        });
    }

    public function findPromotionCode(string $code): ?PromotionCode
    {
        return $this->call('promotion_code', static function (StripeClient $c) use ($code): ?PromotionCode {
            $found = $c->promotionCodes->all(['code' => $code, 'active' => true, 'limit' => 1, 'expand' => ['data.coupon']])->data[0] ?? null;
            if (null === $found) {
                return null;
            }
            $coupon = $found->coupon;
            $value = null !== $coupon->percent_off
                ? '−'.rtrim(rtrim(number_format((float) $coupon->percent_off, 2, ',', ''), '0'), ',').' %'
                : '−'.number_format(((int) $coupon->amount_off) / 100, 2, ',', ' ').' €';
            $duration = match ($coupon->duration) {
                'once' => 'sur la première échéance',
                'repeating' => 'pendant '.(int) $coupon->duration_in_months.' mois',
                default => 'pendant toute la durée de l’abonnement',
            };

            return new PromotionCode((string) $found->id, (string) $found->code, $value.' '.$duration);
        });
    }

    public function createSubscriptionCheckout(CheckoutRequest $request): CheckoutSession
    {
        return $this->call('checkout', static function (StripeClient $c) use ($request): CheckoutSession {
            $params = [
                'mode' => 'subscription',
                'customer' => $request->customerId,
                'client_reference_id' => $request->clientReference,
                'line_items' => [['price' => $request->priceId, 'quantity' => 1]],
                'success_url' => $request->successUrl,
                'cancel_url' => $request->cancelUrl,
                'locale' => 'fr',
                'automatic_tax' => ['enabled' => $request->automaticTax],
                'customer_update' => ['address' => 'auto', 'name' => 'auto'],
                'billing_address_collection' => 'required',
                'metadata' => $request->metadata,
                'subscription_data' => ['metadata' => $request->metadata],
            ];
            if (null !== $request->promotionCodeId) {
                $params['discounts'] = [['promotion_code' => $request->promotionCodeId]];
            }
            $session = $c->checkout->sessions->create($params, ['idempotency_key' => $request->idempotencyKey]);

            return new CheckoutSession((string) $session->id, (string) $session->url);
        });
    }

    public function retrieveSubscription(string $subscriptionId): SubscriptionState
    {
        return $this->call('subscription', static function (StripeClient $c) use ($subscriptionId): SubscriptionState {
            $s = $c->subscriptions->retrieve($subscriptionId);
            // Depuis l'API « basil », la période en cours est portée par la ligne d'abonnement (items).
            $item = $s->items->data[0] ?? null;
            $date = static fn (mixed $ts): ?\DateTimeImmutable => \is_int($ts) ? new \DateTimeImmutable('@'.$ts) : null;
            $metadata = [];
            foreach ($s->metadata->toArray() as $key => $value) {
                if (\is_scalar($value)) {
                    $metadata[(string) $key] = (string) $value;
                }
            }

            return new SubscriptionState(
                (string) $s->id,
                (string) $s->status,
                null === $item ? null : (string) $item->price->id,
                $date($item?->current_period_start),
                $date($item?->current_period_end),
                (bool) $s->cancel_at_period_end,
                $metadata,
            );
        });
    }

    public function cancelSubscription(string $subscriptionId, string $idempotencyKey): void
    {
        $this->call('subscription', static function (StripeClient $c) use ($subscriptionId, $idempotencyKey): string {
            $current = $c->subscriptions->retrieve($subscriptionId);
            if ('canceled' !== $current->status) {
                $c->subscriptions->cancel($subscriptionId, ['invoice_now' => false, 'prorate' => false], ['idempotency_key' => $idempotencyKey]);
            }

            return $subscriptionId;
        });
    }

    public function changeSubscriptionPrice(string $subscriptionId, string $priceId, string $prorationBehavior, string $idempotencyKey): void
    {
        $this->call('subscription', static function (StripeClient $c) use ($subscriptionId, $priceId, $prorationBehavior, $idempotencyKey): string {
            $subscription = self::releaseSchedule($c, $subscriptionId, $idempotencyKey);
            $item = $subscription->items->data[0] ?? throw new \UnexpectedValueException('Abonnement sans ligne.');
            $c->subscriptions->update($subscriptionId, [
                'items' => [['id' => $item->id, 'price' => $priceId]],
                'proration_behavior' => $prorationBehavior,
                'payment_behavior' => 'pending_if_incomplete',
            ], ['idempotency_key' => $idempotencyKey]);

            return $subscriptionId;
        });
    }

    public function scheduleSubscriptionPrice(string $subscriptionId, string $priceId, string $idempotencyKey): void
    {
        $this->call('subscription_schedule', static function (StripeClient $c) use ($subscriptionId, $priceId, $idempotencyKey): string {
            $subscription = $c->subscriptions->retrieve($subscriptionId);
            $item = $subscription->items->data[0] ?? throw new \UnexpectedValueException('Abonnement sans ligne.');
            $scheduleId = \is_string($subscription->schedule) ? $subscription->schedule : ($subscription->schedule->id ?? null);
            if (null === $scheduleId) {
                $scheduleId = (string) $c->subscriptionSchedules->create(['from_subscription' => $subscriptionId], ['idempotency_key' => $idempotencyKey.'-schedule'])->id;
            }
            $c->subscriptionSchedules->update($scheduleId, [
                'end_behavior' => 'release',
                'phases' => [
                    ['items' => [['price' => $item->price->id, 'quantity' => 1]], 'start_date' => $item->current_period_start, 'end_date' => $item->current_period_end],
                    ['items' => [['price' => $priceId, 'quantity' => 1]], 'proration_behavior' => 'none'],
                ],
            ], ['idempotency_key' => $idempotencyKey]);

            return $scheduleId;
        });
    }

    public function setCancelAtPeriodEnd(string $subscriptionId, bool $cancel, string $idempotencyKey): void
    {
        $this->call('subscription', static function (StripeClient $c) use ($subscriptionId, $cancel, $idempotencyKey): string {
            self::releaseSchedule($c, $subscriptionId, $idempotencyKey);
            $c->subscriptions->update($subscriptionId, ['cancel_at_period_end' => $cancel], ['idempotency_key' => $idempotencyKey]);

            return $subscriptionId;
        });
    }

    /** Un abonnement piloté par un calendrier (changement programmé) n'accepte pas de modification directe. */
    private static function releaseSchedule(StripeClient $c, string $subscriptionId, string $idempotencyKey): \Stripe\Subscription
    {
        $subscription = $c->subscriptions->retrieve($subscriptionId);
        $scheduleId = \is_string($subscription->schedule) ? $subscription->schedule : ($subscription->schedule->id ?? null);
        if (null !== $scheduleId) {
            $c->subscriptionSchedules->release($scheduleId, [], ['idempotency_key' => $idempotencyKey.'-release']);
            $subscription = $c->subscriptions->retrieve($subscriptionId);
        }

        return $subscription;
    }

    /**
     * @template T
     *
     * @param callable(StripeClient): T $request
     *
     * @return T
     */
    private function call(string $object, callable $request): mixed
    {
        if (!$this->isConfigured()) {
            throw new ExternalServiceUnavailable(self::SERVICE, 'clé secrète non configurée');
        }
        // Transport statique de stripe-php confié au client Symfony protégé (hôte fixe, anti-SSRF — cadrage §5.3).
        ApiRequestor::setHttpClient(new SymfonyStripeHttpClient($this->http));
        $this->client ??= new StripeClient(['api_key' => (string) $this->secretKey, 'stripe_version' => self::API_VERSION, 'max_network_retries' => 0]);
        try {
            return $request($this->client);
        } catch (ApiErrorException $e) {
            // Message et code Stripe seulement : ni clé, ni paramètres (adresse du client) dans les journaux.
            $this->logger->warning('Stripe : requête refusée.', ['object' => $object, 'status' => $e->getHttpStatus(), 'code' => $e->getStripeCode(), 'request_id' => $e->getRequestId()]);
            throw new ExternalServiceUnavailable(self::SERVICE, $e->getMessage(), $e);
        }
    }
}
