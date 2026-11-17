<?php

declare(strict_types=1);

namespace App\Identity\Domain\Consent;

/**
 * Catégories de traceurs (CDC §3.2). « necessary » est exemptée de consentement et ne peut être refusée.
 */
enum CookieCategory: string
{
    case Necessary = 'necessary';
    case Audience = 'audience';
    case Social = 'social';
    case Marketing = 'marketing';

    public function requiresConsent(): bool
    {
        return self::Necessary !== $this;
    }

    /** @return list<self> */
    public static function optional(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $c): bool => $c->requiresConsent()));
    }
}
