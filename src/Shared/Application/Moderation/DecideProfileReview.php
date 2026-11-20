<?php

declare(strict_types=1);

namespace App\Shared\Application\Moderation;

use App\Shared\Application\Bus\Command;

/**
 * BO05 « Contenus de profils » (RG-031) — relecture humaine : contenu validé, ou retiré du profil public (présentation
 * vidée, prestations retirées, réalisation ou photo supprimée) avec un motif normalisé. Traitée par ArtisanProfile.
 */
final readonly class DecideProfileReview implements Command
{
    public const array REASONS = [
        'offensive' => 'Injurieux',
        'off_topic' => 'Hors sujet',
        'personal_data' => 'Données personnelles',
        'conflict_of_interest' => 'Conflit d’intérêts',
    ];

    public function __construct(public string $adminId, public string $reviewId, public bool $approve, public ?string $reason = null)
    {
    }
}
