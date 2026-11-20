<?php

declare(strict_types=1);

namespace App\Shared\Application\Storage\Port;

/** Deux espaces privés distincts : rien n'est servi depuis la quarantaine. */
enum StorageZone: string
{
    case Quarantine = 'quarantine';
    case Documents = 'documents';
}
