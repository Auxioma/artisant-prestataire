<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Payment;

use App\Billing\Application\Port\PaymentGateway;
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

    /**
     * @param callable(StripeClient): string $request
     */
    private function call(string $object, callable $request): string
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
