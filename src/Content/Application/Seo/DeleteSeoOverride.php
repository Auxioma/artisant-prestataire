<?php

declare(strict_types=1);

namespace App\Content\Application\Seo;

use App\Shared\Application\Bus\Command;

/** BO12.1 — retire la surcharge d'une page. */
final readonly class DeleteSeoOverride implements Command
{
    public function __construct(public string $adminId, public string $path)
    {
    }
}
