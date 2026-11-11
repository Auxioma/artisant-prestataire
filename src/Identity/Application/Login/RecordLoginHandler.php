<?php

declare(strict_types=1);

namespace App\Identity\Application\Login;

use App\Identity\Application\Port\IdentityMailer;
use App\Identity\Application\Port\KnownDevices;
use App\Identity\Domain\User\UserId;
use App\Identity\Domain\User\UserRepository;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

final readonly class RecordLoginHandler implements CommandHandler
{
    public function __construct(
        private UserRepository $users,
        private KnownDevices $devices,
        private IdentityMailer $mailer,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(RecordLogin $command): void
    {
        $now = $this->clock->now();
        $user = $this->users->get(new UserId($command->userId));
        $user->recordLogin($now);

        $description = DeviceDescription::fromUserAgent($command->userAgent);
        $firstDevice = 0 === $this->devices->count($command->userId);
        $isNew = $this->devices->touch($this->ids->next(), $command->userId, hash('sha256', $command->deviceToken), $description, $command->ipHash, $now);
        // Pas d'alerte pour le tout premier appareil (celui de l'inscription) : seuls les suivants sont signalés.
        if ($isNew && !$firstDevice) {
            $this->mailer->sendNewDeviceAlert($user->email(), $user->firstName(), $description, $now);
        }

        $this->audit->record(new AuditRecord(
            id: $this->ids->next(),
            occurredAt: $now,
            actorType: 'user',
            actorId: $command->userId,
            action: 'identity.login',
            subjectType: 'user',
            subjectId: $command->userId,
            ip: $command->ip,
            context: ['method' => $command->method, 'new_device' => $isNew, 'device' => $description],
        ));
    }
}
