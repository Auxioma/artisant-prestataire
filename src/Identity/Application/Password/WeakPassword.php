<?php

declare(strict_types=1);

namespace App\Identity\Application\Password;

/** Mot de passe refusé ; `reason` choisit le message affiché, `minLength` le complète. */
final class WeakPassword extends \DomainException
{
    public const string TOO_SHORT = 'too_short';
    public const string TOO_LONG = 'too_long';
    public const string COMPROMISED = 'compromised';
    public const string CONTAINS_EMAIL = 'contains_email';

    public function __construct(public readonly string $reason, public readonly int $minLength)
    {
        parent::__construct('Mot de passe refusé : '.$reason);
    }
}
