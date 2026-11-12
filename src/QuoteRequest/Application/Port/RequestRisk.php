<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Port;

final readonly class RequestRisk
{
    /** @param list<string> $signals codes des signaux relevés (ex. « contact_in_description ») */
    public function __construct(public int $score, public array $signals)
    {
    }
}
