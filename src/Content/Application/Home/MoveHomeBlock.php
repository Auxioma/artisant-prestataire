<?php

declare(strict_types=1);

namespace App\Content\Application\Home;

use App\Shared\Application\Bus\Command;

/** BO10.1 — Réordonne un bloc (-1 : monter, +1 : descendre) ; en-tête et pied de page restent aux extrémités. */
final readonly class MoveHomeBlock implements Command
{
    public function __construct(public string $adminId, public string $type, public int $offset)
    {
    }
}
