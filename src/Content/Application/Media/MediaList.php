<?php

declare(strict_types=1);

namespace App\Content\Application\Media;

use App\Shared\Application\Bus\Query;

/** Réponse : list<MediaView> (ou ?MediaView si `id` est fourni). */
final readonly class MediaList implements Query
{
    public function __construct(public ?string $id = null)
    {
    }
}
