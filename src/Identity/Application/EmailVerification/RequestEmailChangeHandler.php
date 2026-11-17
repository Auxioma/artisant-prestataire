<?php

declare(strict_types=1);

namespace App\Identity\Application\EmailVerification;

use App\Identity\Application\Command\InvalidCurrentPassword;
use App\Identity\Application\Port\DisposableEmailDetector;
use App\Identity\Application\Port\IdentityMailer;
use App\Identity\Application\Port\PasswordHasher;
use App\Identity\Application\Registration\RegistrationRejected;
use App\Identity\Domain\User\UserId;
use App\Identity\Domain\User\UserRepository;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;
use App\Shared\Domain\ValueObject\Email;

/**
 * Anti-énumération : si la nouvelle adresse appartient déjà à un compte, aucune erreur — son titulaire est prévenu et
 * aucun lien de changement n'est émis.
 */
final readonly class RequestEmailChangeHandler implements CommandHandler
{
    public function __construct(
        private UserRepository $users,
        private PasswordHasher $hasher,
        private DisposableEmailDetector $disposable,
        private SettingsProvider $settings,
        private EmailVerificationSender $sender,
        private IdentityMailer $mailer,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(RequestEmailChange $command): void
    {
        $user = $this->users->get(new UserId($command->userId));
        if (!$this->hasher->verify($user->passwordHash(), $command->currentPassword)) {
            throw new InvalidCurrentPassword('Mot de passe actuel incorrect.');
        }
        $newEmail = new Email($command->newEmail);
        if ($this->settings->bool(SettingKey::AntifraudBlockDisposableEmails) && $this->disposable->isDisposable($newEmail)) {
            throw new RegistrationRejected('email', RegistrationRejected::EMAIL_DISPOSABLE);
        }
        if ($newEmail->equals($user->email())) {
            return;
        }

        $owner = $this->users->findByEmail($newEmail);
        if (null !== $owner) {
            $this->mailer->sendExistingAccountNotice($newEmail, $owner->firstName());
        } else {
            $this->sender->send($user, EmailTokenPurpose::EmailChange, $newEmail, respectCooldown: false);
        }
        $this->audit->record(new AuditRecord(
            id: $this->ids->next(),
            occurredAt: $this->clock->now(),
            actorType: 'user',
            actorId: $command->userId,
            action: 'identity.email_change_requested',
            subjectType: 'user',
            subjectId: $command->userId,
            ip: $command->ip,
        ));
    }
}
