<?php

declare(strict_types=1);

namespace App\Quote\Application\Pro;

use App\QuoteRequest\Application\Pro\ProRequestCard;

final readonly class ProDashboardView
{
    /**
     * @param list<ProRequestCard>                                         $latest   dernières demandes reçues
     * @param array{pending: int, accepted: int, works: int, done: int}    $quotes
     * @param array{requests: int, sent: int, accepted: int, revenue: int} $activity
     */
    public function __construct(
        public int $newRequests,
        public array $latest,
        public array $quotes,
        public int $clients,
        public array $activity,
        public int $days,
        public ?int $completion,
        public bool $verified,
    ) {
    }
}
