<?php

declare(strict_types=1);

namespace App\Review\Domain\Review;

/** RG-035 — motifs normalisés de refus d'un avis ou d'une réponse (communiqués à l'auteur). */
enum RejectionReason: string
{
    case Offensive = 'offensive';
    case OffTopic = 'off_topic';
    case PersonalData = 'personal_data';
    case ConflictOfInterest = 'conflict_of_interest';

    public function label(): string
    {
        return match ($this) {
            self::Offensive => 'Propos injurieux',
            self::OffTopic => 'Hors sujet',
            self::PersonalData => 'Données personnelles',
            self::ConflictOfInterest => 'Conflit d’intérêts',
        };
    }
}
