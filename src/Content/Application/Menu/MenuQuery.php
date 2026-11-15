<?php

declare(strict_types=1);

namespace App\Content\Application\Menu;

use App\Shared\Application\Bus\Query;

/** Réponse : list<MenuItem> (tous les menus, par menu puis position). */
final readonly class MenuQuery implements Query
{
}
