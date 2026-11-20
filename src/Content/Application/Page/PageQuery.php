<?php

declare(strict_types=1);

namespace App\Content\Application\Page;

use App\Shared\Application\Bus\Query;

/** Réponse : list<PageView> (`all`), ?PageView (`id` ou `slug`). */
final readonly class PageQuery implements Query
{
    public function __construct(public ?string $id = null, public ?string $slug = null)
    {
    }
}
