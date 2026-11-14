<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Payment;

use App\Billing\Application\Port\PaymentGateway;
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

    public function reset(): void
    {
        [$this->responses, $this->calls, $this->configured, $this->failing] = [[], [], true, false];
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
