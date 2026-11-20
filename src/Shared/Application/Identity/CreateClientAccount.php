<?php

declare(strict_types=1);

namespace App\Shared\Application\Identity;

use App\Shared\Application\Bus\Command;

/**
 * P11.6 (Figma 5:162) — compte client créé dans le tunnel de demande, sur les règles d'A02 (CGU et politique de
 * confidentialité en vigueur acceptées, mot de passe contrôlé, téléphone requis). Traitée par Identity ; e-mail
 * d'activation E-11. Anti-énumération : si l'adresse est déjà inscrite, rien n'est créé et aucune erreur n'est
 * renvoyée (le titulaire est prévenu). AccountCreationRejected si une saisie est refusée.
 */
final readonly class CreateClientAccount implements Command
{
    public function __construct(
        public string $userId,
        public string $email,
        /** null : « Créer mon compte plus tard » (lien magique, aucun mot de passe choisi) */
        #[\SensitiveParameter]
        public ?string $password,
        public string $firstName,
        public string $lastName,
        public string $phone,
        public string $postalCode,
        public bool $newsletter,
        public ?string $ip = null,
        public ?string $ipHash = null,
    ) {
    }
}
