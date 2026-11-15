<?php

declare(strict_types=1);

namespace App\Identity\Application\Privacy;

use App\Identity\Application\Port\IdentityMailer;
use App\Identity\Application\Port\KnownDevices;
use App\Identity\Domain\Client\ClientProfileRepository;
use App\Identity\Domain\User\UserRepository;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Application\Storage\FileShredder;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Event\AccountErased;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/**
 * RG-090 — anonymisation irréversible : identité, téléphone, photo, appareils, archives d'export, préférences du profil ;
 * les autres modules réagissent à AccountErased. Restent les preuves soumises à obligation légale (documents acceptés,
 * journaux de sécurité, devis et factures sous un compte anonyme). E-09 part à l'adresse d'origine.
 */
final readonly class EraseDueAccountsHandler implements CommandHandler
{
    public function __construct(
        private UserRepository $users,
        private ClientProfileRepository $profiles,
        private KnownDevices $devices,
        private DataExports $exports,
        private FileShredder $shredder,
        private IdentityMailer $mailer,
        private SettingsProvider $settings,
        private EventBus $events,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(EraseDueAccounts $command): void
    {
        $now = $this->clock->now();
        $before = $now->modify(\sprintf('-%d days', $this->settings->int(SettingKey::GdprDeletionGraceDays)));
        foreach ($this->users->dueForErasure($before) as $id) {
            $user = $this->users->get($id);
            $email = $user->email();
            $firstName = $user->firstName();
            $avatar = $this->profiles->find($id)?->erase($now);
            $user->anonymize($now);
            $this->devices->forget($id->value);
            foreach (array_filter([$avatar, ...$this->exports->forget($id->value)]) as $file) {
                $this->shredder->shred($file, 'account_erased');
            }
            $this->events->publish(...$user->releaseEvents());
            $this->events->publish(new AccountErased($id->value, $now));
            $this->audit->record(new AuditRecord(
                id: $this->ids->next(),
                occurredAt: $now,
                actorType: 'system',
                actorId: null,
                action: 'gdpr.account_erased',
                subjectType: 'user',
                subjectId: $id->value,
            ));
            $this->mailer->sendAccountDeleted($email, $firstName);
        }
    }
}
