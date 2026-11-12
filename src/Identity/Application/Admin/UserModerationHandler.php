<?php

declare(strict_types=1);

namespace App\Identity\Application\Admin;

use App\Identity\Application\Port\IdentityMailer;
use App\Identity\Domain\User\IdentityRuleViolation;
use App\Identity\Domain\User\UserId;
use App\Identity\Domain\User\UserRepository;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/** BO02 — suspension et réactivation d'un compte par un administrateur : journalisées, titulaire prévenu (E-26). */
final readonly class UserModerationHandler implements CommandHandler
{
    public function __construct(
        private UserRepository $users,
        private IdentityMailer $mailer,
        private EventBus $events,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(SuspendUser|ReinstateUser $command): void
    {
        $user = (1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $command->userId) ? $this->users->find(new UserId($command->userId)) : null) ?? throw new IdentityRuleViolation('Compte introuvable.');
        $now = $this->clock->now();
        $suspend = $command instanceof SuspendUser;
        if ($suspend) {
            $user->suspend($command->reason, $now);
        } else {
            $user->reinstate($now);
        }
        $this->events->publish(...$user->releaseEvents());
        $this->audit->record(new AuditRecord(
            id: $this->ids->next(),
            occurredAt: $now,
            actorType: 'admin',
            actorId: $command->adminId,
            action: $suspend ? 'identity.user_suspended' : 'identity.user_reinstated',
            subjectType: 'user',
            subjectId: $user->id()->value,
        ));
        $this->mailer->sendAccountStatus($user->email(), $user->firstName(), $suspend, $suspend ? trim($command->reason) : null, $user->id()->value);
    }
}
