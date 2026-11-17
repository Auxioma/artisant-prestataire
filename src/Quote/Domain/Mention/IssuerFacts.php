<?php

declare(strict_types=1);

namespace App\Quote\Domain\Mention;

/** Éléments de l'entreprise émettrice utiles au contrôle RG-120 (fournis par le module ArtisanProfile). */
final readonly class IssuerFacts
{
    public function __construct(
        public ?string $legalName,
        public ?string $siret,
        public ?string $address,
        public bool $insuranceRequired,
        public bool $decennialRequired,
        public bool $liabilityInsured,
        public bool $decennialInsured,
    ) {
    }
}
