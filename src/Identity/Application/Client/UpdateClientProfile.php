<?php

declare(strict_types=1);

namespace App\Identity\Application\Client;

use App\Shared\Application\Bus\Command;

/** C07.1 « Enregistrer les modifications » : identité, téléphone, localisation, rayon, domaines d'intérêt, newsletter. */
final readonly class UpdateClientProfile implements Command
{
    /** @param list<string> $interests valeurs de ClientInterest */
    public function __construct(
        public string $userId,
        public string $firstName,
        public string $lastName,
        public string $phone,
        public string $postalCode,
        public ?int $searchRadiusKm,
        public array $interests,
        public bool $newsletter,
    ) {
    }
}
