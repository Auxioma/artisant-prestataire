<?php

declare(strict_types=1);

namespace App\Identity\Application\EmailVerification;

/**
 * Jeton à usage unique : 256 bits d'aléa (ASVS 2.5.1 / 3.5), encodé base64url (43 caractères) ; seule son empreinte
 * SHA-256 est stockée — une fuite de la base ne permet pas de valider une adresse.
 */
final readonly class SecretToken
{
    public const string PATTERN = '[A-Za-z0-9_-]{43}';

    private function __construct(public string $plain, public string $hash)
    {
    }

    public static function generate(): self
    {
        $plain = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return new self($plain, self::hashOf($plain));
    }

    public static function hashOf(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public static function isWellFormed(string $plain): bool
    {
        return 1 === preg_match('/^'.self::PATTERN.'$/', $plain);
    }
}
