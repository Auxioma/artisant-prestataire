<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Signup;

use App\Shared\Application\Bus\Command;

/**
 * A03.2. Données issues de Sirene quand elle répond ; saisie manuelle (`manualCompanyName`, code postal, ville) sinon,
 * avec vérification différée.
 */
final readonly class DeclareCompany implements Command
{
    public function __construct(
        public string $userId,
        public string $siret,
        public ?string $tradeName,
        public ?string $vatNumber,
        public string $vatStatus,
        public ?string $manualCompanyName = null,
        public ?string $manualPostalCode = null,
        public ?string $manualCity = null,
    ) {
    }
}
