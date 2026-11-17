<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

use App\Identity\Application\Password\PasswordPolicy;
use App\Identity\Application\Port\PasswordHasher;
use App\Identity\Domain\User\UserId;
use App\Identity\Domain\User\UserRepository;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

final readonly class ChangePasswordHandler implements CommandHandler
{
    public function __construct(
        private UserRepository $users,
        private PasswordPolicy $policy,
        private PasswordHasher $hasher,
        private EventBus $events,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(ChangePassword $command): void
    {
        $user = $this->users->get(new UserId($command->userId));
        if (!$this->hasher->verify($user->passwordHash(), $command->currentPassword)) {
            throw new InvalidCurrentPassword('Mot de passe actuel incorrect.');
        }
        $this->policy->assertAcceptable($command->newPassword, $user->email());

        $now = $this->clock->now();
        $user->changePassword($this->hasher->hash($command->newPassword), $now);
        $this->events->publish(...$user->releaseEvents());
        $this->audit->record(new AuditRecord(
            id: $this->ids->next(),
            occurredAt: $now,
            actorType: 'user',
            actorId: $command->userId,
            action: 'identity.password_changed',
            subjectType: 'user',
            subjectId: $command->userId,
            ip: $command->ip,
        ));
    }
}
