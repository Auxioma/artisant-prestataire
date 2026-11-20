<?php

declare(strict_types=1);

namespace App\Content\Application\Retention;

use App\Content\Application\Port\ContactMessages;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Storage\FileShredder;
use App\Shared\Domain\Time\Clock;

final readonly class PurgeContactMessagesHandler implements CommandHandler
{
    /** CDC §11 : prospects conservés 3 ans après le dernier contact. */
    public const int RETENTION_YEARS = 3;

    public function __construct(private ContactMessages $messages, private FileShredder $shredder, private Clock $clock)
    {
    }

    public function __invoke(PurgeContactMessages $command): void
    {
        foreach ($this->messages->purgeBefore($this->clock->now()->modify(\sprintf('-%d years', self::RETENTION_YEARS))) as $file) {
            $this->shredder->shred($file, 'contact_retention');
        }
    }
}
