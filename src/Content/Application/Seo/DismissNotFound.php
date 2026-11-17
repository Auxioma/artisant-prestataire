<?php

declare(strict_types=1);

namespace App\Content\Application\Seo;

use App\Shared\Application\Bus\Command;

/** BO12.2 — retire un chemin du rapport des 404 (traité ou sans intérêt). */
final readonly class DismissNotFound implements Command
{
    public function __construct(public string $adminId, public string $path)
    {
    }
}
