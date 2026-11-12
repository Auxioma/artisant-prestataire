<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Edit;

use App\Shared\Application\Bus\Command;

/** PR08.8 — horaires (plages par jour), urgences, option « afficher mon téléphone » (RG-030). */
final readonly class UpdateAvailability implements Command
{
    /** @param array<string, list<string>> $days */
    public function __construct(public string $userId, public array $days, public bool $emergencies, public ?string $emergencyNote, public bool $phonePublic)
    {
    }
}
