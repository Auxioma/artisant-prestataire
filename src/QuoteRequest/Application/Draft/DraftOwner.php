<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Draft;

use App\QuoteRequest\Domain\Request\RequestRuleViolation;

/**
 * Propriétaire d'un brouillon (P11.1 « session + compte si connecté ») : le compte connecté, ou un visiteur identifié
 * par un jeton aléatoire conservé dans un cookie ; seule l'empreinte du jeton est stockée.
 */
final readonly class DraftOwner
{
    private const string TOKEN = '/^[A-Za-z0-9_-]{43}$/';
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const string HASH = 'sha256';

    private function __construct(public ?string $userId, public ?string $tokenHash)
    {
    }

    public static function user(string $userId): self
    {
        if (1 !== preg_match(self::UUID, $userId)) {
            throw new RequestRuleViolation('Compte invalide.');
        }

        return new self($userId, null);
    }

    public static function visitor(string $token): self
    {
        if (1 !== preg_match(self::TOKEN, $token)) {
            throw new RequestRuleViolation('Jeton de brouillon invalide.');
        }

        return new self(null, hash(self::HASH, $token));
    }
}
