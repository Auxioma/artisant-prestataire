<?php

declare(strict_types=1);

namespace App\Billing\Application\Port;

/** Coupon + code promo à créer chez le prestataire (BO08.3) ; produits éligibles = Products Stripe des forfaits. */
final readonly class PromotionSpec
{
    /**
     * @param list<string>          $productIds vide = tous les produits
     * @param array<string, string> $metadata
     */
    public function __construct(
        public string $code,
        public ?float $percentOff,
        public ?int $amountOffCents,
        public string $duration,
        public ?int $durationMonths,
        public array $productIds,
        public ?\DateTimeImmutable $expiresAt,
        public ?int $maxRedemptions,
        public bool $firstTimeOnly,
        public array $metadata,
        public string $idempotencyKey,
    ) {
    }
}
