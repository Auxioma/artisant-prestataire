<?php

declare(strict_types=1);

namespace App\Identity\Application\EmailVerification;

use App\Shared\Application\Bus\Command;

/** A06 : clic sur le lien reçu. Le jeton est la seule preuve requise (possession de la boîte). */
final readonly class ConfirmEmail implements Command
{
    public function __construct(#[\SensitiveParameter] public string $token, public ?string $ip = null)
    {
    }
}
