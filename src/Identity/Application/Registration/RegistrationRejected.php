<?php

declare(strict_types=1);

namespace App\Identity\Application\Registration;

/** Saisie refusée par une règle métier ; `field` situe le message dans le formulaire (validation serveur, A02). */
final class RegistrationRejected extends \DomainException
{
    public const string EMAIL_DISPOSABLE = 'email_disposable';
    public const string POSTAL_CODE_UNKNOWN = 'postal_code_unknown';
    public const string SIRET_UNKNOWN = 'siret_unknown';
    public const string SIRET_CLOSED = 'siret_closed';
    public const string LEGAL_OUTDATED = 'legal_outdated';
    public const string MOBILE_REQUIRED = 'mobile_required';

    public function __construct(public readonly string $field, public readonly string $reason)
    {
        parent::__construct(\sprintf('Inscription refusée (%s : %s).', $field, $reason));
    }
}
