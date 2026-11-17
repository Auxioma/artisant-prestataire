<?php

declare(strict_types=1);

namespace App\Administration\Application\Team;

/**
 * Codes de secours 2FA (ADR-0013) : 10 codes à usage unique de 10 caractères (alphabet sans ambiguïté, ~48 bits),
 * affichés une seule fois ; conservés sous forme d'empreinte HMAC (clé applicative), jamais en clair.
 */
final readonly class BackupCodes
{
    public const int COUNT = 10;
    private const string ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';
    private const int LENGTH = 10;

    public function __construct(#[\SensitiveParameter] private string $pepper)
    {
    }

    /** @return list<string> codes en clair, au format « xxxxx-xxxxx » */
    public function generate(): array
    {
        $codes = [];
        while (\count($codes) < self::COUNT) {
            $code = '';
            for ($i = 0; $i < self::LENGTH; ++$i) {
                $code .= self::ALPHABET[random_int(0, \strlen(self::ALPHABET) - 1)];
            }
            $codes[] = substr($code, 0, 5).'-'.substr($code, 5);
        }

        return array_values(array_unique($codes));
    }

    public function hash(#[\SensitiveParameter] string $code): string
    {
        return hash_hmac('sha256', strtolower((string) preg_replace('/[^a-z0-9]/i', '', $code)), $this->pepper);
    }
}
