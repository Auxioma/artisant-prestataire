<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\PublicProfile;

/**
 * Assurance ou qualification affichée sur la fiche. `verified` (RG-032) : le justificatif correspondant a été contrôlé
 * par l'équipe et reste valide à la date du jour ; sinon la mention reste « déclarée ».
 */
final readonly class PublicCredential
{
    public function __construct(
        public string $label,
        public ?string $issuer,
        public ?\DateTimeImmutable $validUntil,
        public bool $verified,
    ) {
    }
}
