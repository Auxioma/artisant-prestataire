<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Client;

/** Brouillon non envoyé du client (C02 « Brouillons », RG-060). */
final readonly class ClientDraft
{
    public function __construct(public ?string $jobName, public int $step, public \DateTimeImmutable $updatedAt, public ?string $title)
    {
    }
}
