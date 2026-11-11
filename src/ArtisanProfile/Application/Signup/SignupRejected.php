<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Signup;

/** Saisie refusée par une règle métier du tunnel A03 ; `field` situe le message (CDC A03, messages et validations). */
final class SignupRejected extends \DomainException
{
    public const string SIRET_INVALID = 'siret_invalid';
    public const string SIRET_TAKEN = 'siret_taken';
    public const string SIRET_CLOSED = 'siret_closed';
    public const string MANUAL_REQUIRED = 'manual_required';
    public const string JOBS_INVALID = 'jobs_invalid';

    public function __construct(public readonly string $field, public readonly string $reason, string $message = '')
    {
        parent::__construct('' === $message ? $reason : $message);
    }
}
