<?php

declare(strict_types=1);

namespace App\Moderation\Application\Report;

/** Motifs de signalement proposés (BO05.2). */
enum ReportReason: string
{
    case FakeReview = 'faux_avis';
    case Offensive = 'injurieux';
    case PersonalData = 'donnees_personnelles';
    case Fraud = 'fraude';
    case OffTopic = 'hors_sujet';
    case Other = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::FakeReview => 'Faux avis ou avis non vécu',
            self::Offensive => 'Propos injurieux ou discriminatoires',
            self::PersonalData => 'Données personnelles exposées',
            self::Fraud => 'Fraude, arnaque ou usurpation',
            self::OffTopic => 'Hors sujet ou publicité',
            self::Other => 'Autre motif',
        };
    }

    /** @return list<self> motifs pertinents pour un type de contenu */
    public static function forType(string $type): array
    {
        return 'avis' === $type ? self::cases() : array_values(array_filter(self::cases(), static fn (self $r): bool => self::FakeReview !== $r));
    }
}
