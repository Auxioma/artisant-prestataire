<?php

declare(strict_types=1);

namespace App\Identity\Application\Privacy;

use App\Identity\Application\Port\IdentityMailer;
use App\Identity\Application\Port\PasswordHasher;
use App\Identity\Domain\User\IdentityRuleViolation;
use App\Identity\Domain\User\UserId;
use App\Identity\Domain\User\UserRepository;
use App\Identity\Domain\User\UserStatus;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/** RG-090 — demande (E-08) et rétractation de la suppression d'un compte ; mot de passe exigé dans les deux cas. */
final readonly class AccountDeletionHandler implements CommandHandler
{
    public function __construct(
        private UserRepository $users,
        private PasswordHasher $hasher,
        private IdentityMailer $mailer,
        private SettingsProvider $settings,
        private EventBus $events,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(RequestAccountDeletion|CancelAccountDeletion $command): void
    {
        $user = $this->users->find(new UserId($command->userId)) ?? throw new AccountDeletionRejected('Compte introuvable.');
        if (!$this->hasher->verify($user->passwordHash(), $command->password)) {
            throw new AccountDeletionRejected('Mot de passe incorrect.');
        }
        $now = $this->clock->now();
        if ($command instanceof CancelAccountDeletion) {
            if (UserStatus::DeletionRequested !== $user->status()) {
                throw new AccountDeletionRejected('Aucune suppression n’est en cours pour ce compte.');
            }
            $user->cancelDeletion($now);
            $this->record($command->userId, 'gdpr.deletion_cancelled', $command->ip, $now);

            return;
        }
        try {
            $user->requestDeletion($now);
        } catch (IdentityRuleViolation) {
            throw new AccountDeletionRejected('Votre compte ne peut pas être supprimé pour le moment : contactez-nous.');
        }
        $this->events->publish(...$user->releaseEvents());
        $effectiveAt = $now->modify(\sprintf('+%d days', $this->settings->int(SettingKey::GdprDeletionGraceDays)));
        $this->mailer->sendDeletionRequested($user->email(), $user->firstName(), $command->userId, $effectiveAt);
        $this->record($command->userId, 'gdpr.deletion_requested', $command->ip, $now);
    }

    private function record(string $userId, string $action, ?string $ip, \DateTimeImmutable $now): void
    {
        $this->audit->record(new AuditRecord(
            id: $this->ids->next(),
            occurredAt: $now,
            actorType: 'user',
            actorId: $userId,
            action: $action,
            subjectType: 'user',
            subjectId: $userId,
            ip: $ip,
        ));
    }
}
