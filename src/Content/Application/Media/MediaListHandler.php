<?php

declare(strict_types=1);

namespace App\Content\Application\Media;

use App\Shared\Application\Bus\QueryHandler;

final readonly class MediaListHandler implements QueryHandler
{
    public function __construct(private MediaLibrary $library)
    {
    }

    /** @return list<MediaView>|MediaView|null */
    public function __invoke(MediaList $query): array|MediaView|null
    {
        return null === $query->id ? $this->library->all() : $this->library->find($query->id);
    }
}
