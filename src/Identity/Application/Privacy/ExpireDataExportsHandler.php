<?php

declare(strict_types=1);

namespace App\Identity\Application\Privacy;

use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Storage\FileShredder;
use App\Shared\Domain\Time\Clock;

final readonly class ExpireDataExportsHandler implements CommandHandler
{
    public function __construct(private DataExports $exports, private FileShredder $shredder, private Clock $clock)
    {
    }

    public function __invoke(ExpireDataExports $command): void
    {
        foreach ($this->exports->expiredBefore($this->clock->now()) as $export) {
            if (null !== $export['fileId']) {
                $this->shredder->shred($export['fileId'], 'data_export_expired');
            }
            $this->exports->markExpired($export['id']);
        }
    }
}
