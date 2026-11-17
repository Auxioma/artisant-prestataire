<?php

declare(strict_types=1);

namespace App\Administration\Domain\Admin;

/** BO14.2 — actions de la matrice de permissions. */
enum AdminAction: string
{
    case View = 'view';
    case Create = 'create';
    case Edit = 'edit';
    case Delete = 'delete';
    case Export = 'export';

    public function label(): string
    {
        return match ($this) {
            self::View => 'Voir',
            self::Create => 'Créer',
            self::Edit => 'Modifier',
            self::Delete => 'Supprimer',
            self::Export => 'Exporter',
        };
    }
}
