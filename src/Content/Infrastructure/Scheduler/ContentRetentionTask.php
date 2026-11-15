<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Scheduler;

use App\Content\Application\Retention\PurgeContactMessages;
use App\Shared\Application\Bus\CommandBus;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/** Chaque nuit : purge des messages de contact au-delà de la durée de conservation (CDC §11). */
#[AsCronTask('55 3 * * *', timezone: 'Europe/Paris')]
final readonly class ContentRetentionTask
{
    public function __construct(private CommandBus $commands)
    {
    }

    public function __invoke(): void
    {
        $this->commands->dispatch(new PurgeContactMessages());
    }
}
