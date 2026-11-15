<?php

declare(strict_types=1);

namespace App\Content\Application\Label;

use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Domain\Time\Clock;

final readonly class SyncLabelsHandler implements CommandHandler
{
    public function __construct(private LabelStore $labels, private Clock $clock)
    {
    }

    public function __invoke(SyncLabels $command): void
    {
        $this->labels->sync($command->defaults, $command->locale, $this->clock->now());
    }
}
