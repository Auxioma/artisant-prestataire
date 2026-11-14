<?php

declare(strict_types=1);

namespace App\Billing\Application\Port;

/** PR13.2 — moyen de paiement par défaut, sans aucune donnée sensible (type, marque, 4 derniers chiffres, expiration). */
final readonly class PaymentMethodSummary
{
    public function __construct(
        public string $type,
        public ?string $brand,
        public ?string $last4,
        public ?int $expMonth,
        public ?int $expYear,
    ) {
    }
}
