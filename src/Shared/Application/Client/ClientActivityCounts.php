<?php

declare(strict_types=1);

namespace App\Shared\Application\Client;

final readonly class ClientActivityCounts
{
    public function __construct(
        public int $requests,
        public int $quotes,
        public int $appointments,
        public int $acceptedQuotes,
    ) {
    }
}
