<?php

declare(strict_types=1);

namespace App\Search\Application\EventHandler;

use App\Search\Application\Favorite\Favorites;
use App\Shared\Application\Bus\EventHandler;
use App\Shared\Domain\Event\AccountErased;

/** RG-090 — compte effacé : ses favoris sont supprimés. */
final readonly class ForgetFavoritesOfErasedAccount implements EventHandler
{
    public function __construct(private Favorites $favorites)
    {
    }

    public function __invoke(AccountErased $event): void
    {
        $this->favorites->forget($event->userId);
    }
}
