<?php

declare(strict_types=1);

namespace App\Identity\Application\Privacy;

use App\Identity\Application\Port\IdentityMailer;
use App\Identity\Domain\User\UserRepository;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/**
 * CDC §11 « inactif 3 ans → relance puis anonymisation » : un compte sans connexion depuis gdpr.inactive_years reçoit
 * E-09b ; sans connexion dans les gdpr.deletion_grace_days jours qui suivent, son effacement est programmé à la date de
 * la relance et réalisé par EraseDueAccounts (même nuit). Un artisan abonné n'est jamais concerné.
 */
final readonly class NoticeInactiveAccountsHandler implements CommandHandler
{
    public function __construct(
        private UserRepository $users,
        private IdentityMailer $mailer,
        private SettingsProvider $settings,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(NoticeInactiveAccounts $command): void
    {
        $now = $this->clock->now();
        $years = $this->settings->int(SettingKey::GdprInactiveYears);
        $grace = $this->settings->int(SettingKey::GdprDeletionGraceDays);

        foreach ($this->users->unansweredInactivityNotices($now->modify(\sprintf('-%d days', $grace))) as $id) {
            $user = $this->users->get($id);
            $user->scheduleErasureForInactivity($now);
            $this->record('gdpr.inactivity_erasure_scheduled', $id->value, $now, ['years' => $years]);
        }

        $effectiveAt = $now->modify(\sprintf('+%d days', $grace));
        foreach ($this->users->inactiveSince($now->modify(\sprintf('-%d years', $years))) as $id) {
            $user = $this->users->get($id);
            $user->noticeInactivity($now);
            $this->mailer->sendInactivityNotice($user->email(), $user->firstName(), $years, $effectiveAt);
            $this->record('gdpr.inactivity_notice', $id->value, $now, ['years' => $years]);
        }
    }

    /** @param array<string, int> $context */
    private function record(string $action, string $userId, \DateTimeImmutable $now, array $context): void
    {
        $this->audit->record(new AuditRecord(
            id: $this->ids->next(),
            occurredAt: $now,
            actorType: 'system',
            actorId: null,
            action: $action,
            subjectType: 'user',
            subjectId: $userId,
            context: $context,
        ));
    }
}
