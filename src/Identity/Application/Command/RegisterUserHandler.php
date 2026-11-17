<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

use App\Identity\Application\Password\PasswordPolicy;
use App\Identity\Application\Port\PasswordHasher;
use App\Identity\Domain\User\PersonName;
use App\Identity\Domain\User\User;
use App\Identity\Domain\User\UserId;
use App\Identity\Domain\User\UserKind;
use App\Identity\Domain\User\UserRepository;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;
use App\Shared\Domain\ValueObject\Email;

final readonly class RegisterUserHandler implements CommandHandler
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

    public function __invoke(RegisterUser $command): void
    {
        $email = new Email($command->email);
        $kind = UserKind::from($command->kind);
        $firstName = new PersonName($command->firstName);
        $lastName = new PersonName($command->lastName);
        $this->policy->assertAcceptable($command->password, $email);
        if (null !== $this->users->findByEmail($email)) {
            throw new EmailAlreadyRegistered();
        }

        $now = $this->clock->now();
        $user = User::register(new UserId($command->userId), $email, $kind, $this->hasher->hash($command->password), $firstName, $lastName, $now);
        $this->users->add($user);
        $this->events->publish(...$user->releaseEvents());
        $this->audit->record(new AuditRecord(
            id: $this->ids->next(),
            occurredAt: $now,
            actorType: 'user',
            actorId: $command->userId,
            action: 'identity.user_registered',
            subjectType: 'user',
            subjectId: $command->userId,
            ip: $command->ip,
            context: ['kind' => $kind->value],
        ));
    }
}
