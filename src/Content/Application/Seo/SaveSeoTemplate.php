<?php

declare(strict_types=1);

namespace App\Content\Application\Seo;

use App\Shared\Application\Bus\Command;

/** BO12.1 — gabarit title / description d'un type de page (vide = texte par défaut). */
final readonly class SaveSeoTemplate implements Command
{
    public function __construct(public string $adminId, public string $pageType, public ?string $title, public ?string $description)
    {
    }
}
