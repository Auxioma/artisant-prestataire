<?php

declare(strict_types=1);

namespace App\Identity\Application\EmailVerification;

use App\Shared\Application\Bus\Command;

/**
 * Renvoi du lien (A02 « renvoyer », A06 « lien expiré », bandeau des espaces). Identifie le compte par l'adresse saisie
 * à l'inscription, par un ancien lien ou par la session. Sans effet visible si le compte n'existe pas ou est déjà validé.
 */
final readonly class ResendEmailVerification implements Command
{
    public function __construct(public ?string $email = null, #[\SensitiveParameter] public ?string $previousToken = null, public ?string $userId = null)
    {
    }
}
