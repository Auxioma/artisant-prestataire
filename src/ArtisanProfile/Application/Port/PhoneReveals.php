<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Port;

/** Journal des affichages du téléphone (RG-030), base des statistiques de l'artisan (tableau de bord, T-309). */
interface PhoneReveals
{
    public function record(string $id, string $profileId, ?string $visitorHash, \DateTimeImmutable $at): void;
}
