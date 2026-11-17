<?php

declare(strict_types=1);

namespace App\Identity\Application\EmailVerification;

use App\Shared\Application\Bus\Command;

/** C07 : changement d'adresse ; mot de passe actuel exigé, nouvelle adresse confirmée par lien (A06). */
final readonly class RequestEmailChange implements Command
{
    public function __construct(public string $userId, #[\SensitiveParameter] public string $currentPassword, public string $newEmail, public ?string $ip = null)
    {
    }
}
