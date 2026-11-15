<?php

declare(strict_types=1);

namespace App\Administration\Application\Retention;

use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

final readonly class PurgeAuditLogHandler implements CommandHandler
{
    public function __construct(private AuditRetention $retention, private SettingsProvider $settings, private AuditTrail $audit, private IdGenerator $ids, private Clock $clock)
    {
    }

    public function __invoke(PurgeAuditLog $command): void
    {
        $now = $this->clock->now();
        $months = $this->settings->int(SettingKey::AuditRetentionMonths);
        $purged = $this->retention->purgeBefore($now->modify(\sprintf('-%d months', $months)), $now);
        if ($purged > 0) {
            $this->audit->record(new AuditRecord($this->ids->next(), $now, 'system', null, 'gdpr.audit_purged', 'audit_log', null, context: ['count' => $purged, 'months' => $months]));
        }
    }
}
