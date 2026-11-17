<?php

declare(strict_types=1);

namespace App\Billing\Application\Port;

/**
 * État courant d'une Subscription chez le prestataire (relu à chaque événement : les webhooks peuvent arriver dans le
 * désordre, ADR-0010). `status` : active, trialing, past_due, unpaid, canceled, incomplete, incomplete_expired, paused.
 */
final readonly class SubscriptionState
{
    /** @param array<string, string> $metadata */
    public function __construct(
        public string $id,
        public string $status,
        public ?string $priceId,
        public ?\DateTimeImmutable $periodStart,
        public ?\DateTimeImmutable $periodEnd,
        public bool $cancelAtPeriodEnd,
        public array $metadata,
    ) {
    }
}
