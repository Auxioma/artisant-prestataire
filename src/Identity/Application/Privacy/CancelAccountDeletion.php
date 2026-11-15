<?php

declare(strict_types=1);

namespace App\Identity\Application\Privacy;

use App\Shared\Application\Bus\Command;

/** Rétractation (lien de l'e-mail E-08) : mot de passe exigé, compte réactivé. */
final readonly class CancelAccountDeletion implements Command
{
    public function __construct(public string $userId, #[\SensitiveParameter] public string $password, public ?string $ip = null)
    {
    }
}
