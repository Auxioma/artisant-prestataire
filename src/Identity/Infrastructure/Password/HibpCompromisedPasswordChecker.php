<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Password;

use App\Identity\Application\Port\CompromisedPasswordChecker;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Contrainte Symfony NotCompromisedPassword (client `hibp.client`, anti-SSRF, en-tête Add-Padding).
 * `skipOnError` : une panne de l'API ne bloque pas l'utilisateur. Désactivée en test (framework.validation).
 */
final readonly class HibpCompromisedPasswordChecker implements CompromisedPasswordChecker
{
    public function __construct(private ValidatorInterface $validator)
    {
    }

    public function isCompromised(#[\SensitiveParameter] string $plain): bool
    {
        return \count($this->validator->validate($plain, new NotCompromisedPassword(skipOnError: true))) > 0;
    }
}
