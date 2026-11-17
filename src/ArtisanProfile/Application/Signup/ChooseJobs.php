<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Signup;

use App\Shared\Application\Bus\Command;

/** A03.3 — 1 à pro.max_jobs métiers, dont un principal. */
final readonly class ChooseJobs implements Command
{
    /** @param list<string> $jobIds */
    public function __construct(public string $userId, public array $jobIds, public string $mainJobId)
    {
    }
}
