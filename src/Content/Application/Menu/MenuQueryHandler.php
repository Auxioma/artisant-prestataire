<?php

declare(strict_types=1);

namespace App\Content\Application\Menu;

use App\Shared\Application\Bus\QueryHandler;

final readonly class MenuQueryHandler implements QueryHandler
{
    public function __construct(private MenuStore $menus)
    {
    }

    /** @return list<MenuItem> */
    public function __invoke(MenuQuery $query): array
    {
        return $this->menus->all();
    }
}
