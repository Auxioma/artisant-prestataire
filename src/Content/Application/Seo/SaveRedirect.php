<?php

declare(strict_types=1);

namespace App\Content\Application\Seo;

use App\Shared\Application\Bus\Command;

/** BO12.2 — redirection 301 / 302 vers un chemin interne (chaînes aplaties, boucles évitées). */
final readonly class SaveRedirect implements Command
{
    public function __construct(public string $adminId, public string $source, public string $target, public int $status = 301, public bool $prefix = false)
    {
    }
}
