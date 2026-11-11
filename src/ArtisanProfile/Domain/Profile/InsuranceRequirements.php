<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Profile;

/** Assurances exigées par les métiers exercés (A03.5, Q16 — paramétrées par métier en BO06). */
final readonly class InsuranceRequirements
{
    public function __construct(public bool $any, public bool $decennial, public bool $liability)
    {
    }
}
