<?php

declare(strict_types=1);

namespace App\Identity\Application\EmailVerification;

final class InvalidEmailToken extends \DomainException
{
    public function __construct(public readonly EmailTokenState $state)
    {
        parent::__construct('Lien de validation inutilisable : '.$state->value);
    }
}
