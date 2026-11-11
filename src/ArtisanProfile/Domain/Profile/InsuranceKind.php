<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Profile;

enum InsuranceKind: string
{
    case Decennial = 'decennial';
    case ProfessionalLiability = 'rc_pro';

    public function label(): string
    {
        return match ($this) {
            self::Decennial => 'Assurance décennale',
            self::ProfessionalLiability => 'RC Professionnelle',
        };
    }
}
