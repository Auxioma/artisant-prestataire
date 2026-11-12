<?php

declare(strict_types=1);

namespace App\Shared\Application\Identity;

/** Saisie refusée à la création d'un compte (message destiné à l'utilisateur, champ concerné). */
final class AccountCreationRejected extends \DomainException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
