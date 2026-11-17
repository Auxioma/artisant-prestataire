<?php

declare(strict_types=1);

namespace App\Identity\Application\Registration;

use App\Shared\Application\Bus\Command;

/** A03.1 — compte du dirigeant : prénom, nom, e-mail, mobile, mot de passe, CGU + CGV pro (+ confidentialité). */
final readonly class RegisterArtisanAccount implements Command
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
        public string $mobile,
        public array $acceptedDocumentIds,
        public ?string $ip = null,
        public ?string $ipHash = null,
    ) {
    }
}
