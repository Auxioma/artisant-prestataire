<?php

declare(strict_types=1);

namespace App\Identity\Application\PasswordReset;

use App\Identity\Application\EmailVerification\SecretToken;
use App\Identity\Application\Port\IdentityMailer;
use App\Identity\Application\Port\PasswordResetTokens;
use App\Identity\Domain\User\UserRepository;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Exception\InvalidValue;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;
use App\Shared\Domain\ValueObject\Email;

final readonly class RequestPasswordResetHandler implements CommandHandler
{
    public function __construct(
        private UserRepository $users,
        private PasswordResetTokens $tokens,
        private IdentityMailer $mailer,
        private SettingsProvider $settings,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(RequestPasswordReset $command): void
    {
        try {
            $user = $this->users->findByEmail(new Email($command->email));
        } catch (InvalidValue) {
            return;
        }
        // Compte suspendu, en cours d'effacement ou anonymisé : aucun lien (la connexion lui est de toute façon refusée).
        if (null === $user || !$user->status()->canLogIn()) {
            return;
        }

        $now = $this->clock->now();
        $ttl = $this->settings->int(SettingKey::AuthPasswordResetTtlMin);
        $token = SecretToken::generate();
        $this->tokens->issue($this->ids->next(), $user->id()->value, $token->hash, $command->ipHash, $now, $now->modify(\sprintf('+%d minutes', $ttl)));
        $this->mailer->sendPasswordResetLink($user->email(), $user->firstName(), $token->plain, $ttl);
        $this->audit->record(new AuditRecord(
            id: $this->ids->next(),
            occurredAt: $now,
            actorType: 'user',
            actorId: $user->id()->value,
            action: 'identity.password_reset_requested',
            subjectType: 'user',
            subjectId: $user->id()->value,
            ip: $command->ip,
        ));
    }
}
