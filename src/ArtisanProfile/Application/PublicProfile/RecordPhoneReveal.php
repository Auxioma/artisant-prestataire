<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\PublicProfile;

use App\Shared\Application\Bus\Command;

/** RG-030 — affichage du téléphone tracé (statistiques de l'artisan) ; empreinte de visiteur anonymisée, jamais l'IP. */
final readonly class RecordPhoneReveal implements Command
{
    public function __construct(public string $profileId, public ?string $visitorHash)
    {
    }
}
