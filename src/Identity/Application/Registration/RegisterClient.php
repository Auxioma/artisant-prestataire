<?php

declare(strict_types=1);

namespace App\Identity\Application\Registration;

use App\Shared\Application\Bus\Command;

/**
 * A02 — inscription client. `acceptedDocumentIds` : versions de CGU et de politique de confidentialité affichées au
 * visiteur ; refus si une nouvelle version a été publiée entre l'affichage et l'envoi (RG-002).
 */
final readonly class RegisterClient implements Command
{
    /**
     * @param list<string> $acceptedDocumentIds
     */
    public function __construct(
        public string $userId,
        public string $email,
        #[\SensitiveParameter]
        public string $password,
        public string $firstName,
        public string $lastName,
        public ?string $phone,
        public string $postalCode,
        public string $clientType,
        public ?string $companyName,
        public ?string $siret,
        public array $acceptedDocumentIds,
        public bool $newsletter,
        public ?string $ip = null,
        public ?string $ipHash = null,
    ) {
    }
}
