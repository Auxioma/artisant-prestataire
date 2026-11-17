<?php

declare(strict_types=1);

namespace App\Billing\Application\Port;

/** PR12.3 — session de paiement hébergée par le prestataire (aucune donnée de carte chez nous, PCI SAQ A). */
final readonly class CheckoutRequest
{
    /**
     * @param array<string, string> $metadata repris sur la session et sur l'abonnement Stripe (rapprochement, webhooks)
     */
    public function __construct(
        public string $customerId,
        public string $priceId,
        public string $clientReference,
        public string $successUrl,
        public string $cancelUrl,
        public bool $automaticTax,
        public ?string $promotionCodeId,
        public array $metadata,
        public string $idempotencyKey,
    ) {
    }
}
