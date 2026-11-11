<?php

declare(strict_types=1);

namespace App\Identity\Application\PasswordReset;

use App\Shared\Application\Bus\Command;

/** A05.1 : demande de lien. Sans effet visible si le compte n'existe pas (réponse identique). */
final readonly class RequestPasswordReset implements Command
{
    public function __construct(public string $email, public ?string $ip = null, public ?string $ipHash = null)
    {
    }
}
