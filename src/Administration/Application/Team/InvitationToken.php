<?php

declare(strict_types=1);

namespace App\Administration\Application\Team;

/** Jeton d'invitation : 256 bits aléatoires (base64url) ; seule son empreinte SHA-256 est conservée. */
final class InvitationToken
{
    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(#[\SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }

    public static function isWellFormed(string $token): bool
    {
        return 1 === preg_match('/^[A-Za-z0-9_-]{43}$/', $token);
    }
}
