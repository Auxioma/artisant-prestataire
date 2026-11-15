<?php

declare(strict_types=1);

namespace App\Moderation\Application\Report;

use App\Shared\Application\Bus\Command;

/** P10 / P10.9 / PR03 — signalement d'un avis, d'un profil ou d'une demande par un utilisateur connecté. */
final readonly class SubmitReport implements Command
{
    public function __construct(public string $reportId, public string $reporterId, public string $type, public string $subjectId, public string $reason, public ?string $details)
    {
    }
}
