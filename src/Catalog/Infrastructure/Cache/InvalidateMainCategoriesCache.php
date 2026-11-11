<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Cache;

use App\Catalog\Application\Query\MainCategories;
use App\Catalog\Domain\Event\CategoryChanged;
use App\Catalog\Domain\Event\CategoryCreated;
use App\Catalog\Domain\Event\JobChanged;
use App\Catalog\Domain\Event\JobCreated;
use App\Shared\Application\Bus\EventHandler;
use Symfony\Contracts\Cache\CacheInterface;

/** Le méga-menu (mis en cache) reflète toute modification du référentiel. */
final readonly class InvalidateMainCategoriesCache implements EventHandler
{
    public function __construct(private CacheInterface $catalogCache)
    {
    }

    public function __invoke(CategoryCreated|CategoryChanged|JobCreated|JobChanged $event): void
    {
        $this->catalogCache->delete(MainCategories::MENU_CACHE_KEY);
    }
}
