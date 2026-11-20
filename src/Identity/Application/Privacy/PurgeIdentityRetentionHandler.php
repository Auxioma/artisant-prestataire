<?php

declare(strict_types=1);

namespace App\Identity\Application\Privacy;

use App\Identity\Application\Port\KnownDevices;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

final readonly class PurgeIdentityRetentionHandler implements CommandHandler
{
    public function __construct(private KnownDevices $devices, private ConsentRetention $consents, private SettingsProvider $settings, private AuditTrail $audit, private IdGenerator $ids, private Clock $clock)
    {
    }

    public function __invoke(PurgeIdentityRetention $command): void
    {
        $now = $this->clock->now();
        $devices = $this->devices->forgetIdleBefore($now->modify(\sprintf('-%d months', $this->settings->int(SettingKey::AuditRetentionMonths))));
        $consents = $this->consents->purgeExpired($now);
        if ($devices + $consents > 0) {
            $this->audit->record(new AuditRecord($this->ids->next(), $now, 'system', null, 'gdpr.identity_purged', 'retention', null, context: ['devices' => $devices, 'consents' => $consents]));
        }
    }
}
