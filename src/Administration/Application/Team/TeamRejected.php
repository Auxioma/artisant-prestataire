<?php

declare(strict_types=1);

namespace App\Administration\Application\Team;

/** Demande refusée sur l'équipe d'administration ; `reason` choisit le message affiché. */
final class TeamRejected extends \DomainException
{
    public const string EMAIL_TAKEN = 'email_taken';
    public const string LAST_SUPER_ADMIN = 'last_super_admin';
    public const string INVITATION_INVALID = 'invitation_invalid';
    public const string TOTP_INVALID = 'totp_invalid';
    public const string UNKNOWN = 'unknown';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
