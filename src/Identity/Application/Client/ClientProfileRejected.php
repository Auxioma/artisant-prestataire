<?php

declare(strict_types=1);

namespace App\Identity\Application\Client;

/** Saisie refusée sur C07 : champ concerné et message affichable. */
final class ClientProfileRejected extends \DomainException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
