<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Dispatch;

/** Alertes E-30 d'un artisan en attente de son prochain digest (demandes toujours ouvertes, plus récentes d'abord). */
final readonly class PendingDigest
{
    /** @param non-empty-list<DispatchSummary> $requests */
    public function __construct(public string $artisanId, public string $userId, public AlertFrequency $frequency, public array $requests)
    {
    }
}
