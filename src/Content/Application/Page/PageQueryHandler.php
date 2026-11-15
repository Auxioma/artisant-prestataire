<?php

declare(strict_types=1);

namespace App\Content\Application\Page;

use App\Shared\Application\Bus\QueryHandler;

final readonly class PageQueryHandler implements QueryHandler
{
    public function __construct(private PageStore $pages)
    {
    }

    /** @return list<PageView>|PageView|null */
    public function __invoke(PageQuery $query): array|PageView|null
    {
        return match (true) {
            null !== $query->id => $this->pages->find($query->id),
            null !== $query->slug => $this->pages->findBySlug($query->slug),
            default => $this->pages->all(),
        };
    }
}
