<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Pro;

use App\QuoteRequest\Application\Port\ProRequestReader;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Domain\Time\Clock;

final readonly class MarkRequestViewedHandler implements CommandHandler
{
    public function __construct(private ProRequestReader $reader, private Clock $clock)
    {
    }

    public function __invoke(MarkRequestViewed $command): void
    {
        $artisan = $this->reader->artisanOf($command->userId);
        if (null !== $artisan && $artisan->verified) {
            $this->reader->markViewed($artisan->artisanId, $command->requestId, $this->clock->now());
        }
    }
}
