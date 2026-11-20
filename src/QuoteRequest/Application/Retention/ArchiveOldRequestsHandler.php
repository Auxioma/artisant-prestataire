<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Retention;

use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Application\Storage\FileShredder;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

final readonly class ArchiveOldRequestsHandler implements CommandHandler
{
    /** Lot traité par passage (la tâche repasse chaque nuit). */
    private const int BATCH = 500;

    public function __construct(private RequestArchive $archive, private FileShredder $shredder, private SettingsProvider $settings, private AuditTrail $audit, private IdGenerator $ids, private Clock $clock)
    {
    }

    public function __invoke(ArchiveOldRequests $command): void
    {
        $now = $this->clock->now();
        $years = $this->settings->int(SettingKey::RequestsRetentionYears);
        $ids = $this->archive->archivableBefore($now->modify(\sprintf('-%d years', $years)), self::BATCH);
        foreach ($ids as $id) {
            foreach ($this->archive->archive($id, $now) as $file) {
                $this->shredder->shred($file, 'requests_retention');
            }
        }
        if ([] !== $ids) {
            $this->audit->record(new AuditRecord($this->ids->next(), $now, 'system', null, 'gdpr.requests_archived', 'quote_request', null, context: ['count' => \count($ids), 'years' => $years]));
        }
    }
}
