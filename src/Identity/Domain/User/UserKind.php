<?php

declare(strict_types=1);

namespace App\Identity\Domain\User;

/** A01 : type de compte choisi à l'inscription (un compte = un rôle ; l'administration est un espace distinct, ADR-0013). */
enum UserKind: string
{
    case Client = 'client';
    case Artisan = 'artisan';
}
