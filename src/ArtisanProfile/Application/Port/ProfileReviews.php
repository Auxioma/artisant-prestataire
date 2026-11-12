<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Port;

/** RG-031 — contenus de profil retenus pour une relecture humaine aléatoire (file BO05). */
interface ProfileReviews
{
    /** @param array<string, mixed> $content */
    public function record(string $id, string $profileId, string $section, array $content, \DateTimeImmutable $at): void;
}
