<?php

declare(strict_types=1);

namespace App\Search\Application\Favorite;

use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Domain\Time\Clock;

final readonly class FavoriteHandler implements CommandHandler
{
    public function __construct(private Favorites $favorites, private Clock $clock)
    {
    }

    public function __invoke(ToggleFavorite $command): void
    {
        if ($command->favorite) {
            $this->favorites->add($command->userId, $command->artisanId, $this->clock->now());

            return;
        }
        $this->favorites->remove($command->userId, $command->artisanId);
    }
}
