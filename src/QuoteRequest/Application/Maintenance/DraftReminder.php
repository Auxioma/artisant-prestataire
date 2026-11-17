<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Maintenance;

/** E-19c — brouillon non finalisé d'un compte client (RG-060). */
final readonly class DraftReminder
{
    public function __construct(public string $draftId, public string $userId, public ?string $jobName)
    {
    }
}
