<?php

declare(strict_types=1);

namespace App\Identity\Application\Security;

use App\Identity\Application\Port\KnownDevices;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

final readonly class RevokeDevicesHandler implements CommandHandler
{
    public function __construct(private KnownDevices $devices, private AuditTrail $audit, private IdGenerator $ids, private Clock $clock)
    {
    }

    public function __invoke(RevokeDevices $command): void
    {
        $now = $this->clock->now();
        $revoked = $this->devices->revoke($command->userId, $command->deviceId, null === $command->deviceId ? $command->currentDeviceHash : null, $now);
        if (0 === $revoked) {
            return;
        }
        $this->audit->record(new AuditRecord(
            id: $this->ids->next(),
            occurredAt: $now,
            actorType: 'user',
            actorId: $command->userId,
            action: 'identity.devices_revoked',
            subjectType: 'user',
            subjectId: $command->userId,
            ip: $command->ip,
            context: ['count' => $revoked, 'scope' => null === $command->deviceId ? 'others' : 'one'],
        ));
    }
}
