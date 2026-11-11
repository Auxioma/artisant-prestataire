<?php

declare(strict_types=1);

namespace App\Identity\Application\PasswordReset;

use App\Identity\Application\EmailVerification\SecretToken;
use App\Identity\Application\Password\PasswordPolicy;
use App\Identity\Application\Port\PasswordHasher;
use App\Identity\Application\Port\PasswordResetTokens;
use App\Identity\Domain\User\UserId;
use App\Identity\Domain\User\UserRepository;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/**
 * Le changement d'empreinte ferme toutes les sessions et invalide les cookies « rester connecté » (A05.2).
 * Le lien prouvant la possession de la boîte, une adresse encore non confirmée devient confirmée.
 * E-04 est envoyé par SendPasswordChangedConfirmation (événement UserPasswordChanged).
 */
final readonly class ResetPasswordHandler implements CommandHandler
{
    public function __construct(
        private PasswordResetTokens $tokens,
        private UserRepository $users,
        private PasswordPolicy $policy,
        private PasswordHasher $hasher,
        private EventBus $events,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(ResetPassword $command): void
    {
        $now = $this->clock->now();
        $token = SecretToken::isWellFormed($command->token) ? $this->tokens->findByHash(SecretToken::hashOf($command->token)) : null;
        if (null === $token || !$token->isUsable($now)) {
            throw new ResetLinkUnusable('Lien de réinitialisation inutilisable.');
        }
        $user = $this->users->get(new UserId($token->userId));
        if (!$user->status()->canLogIn()) {
            throw new ResetLinkUnusable('Compte inaccessible.');
        }
        $this->policy->assertAcceptable($command->newPassword, $user->email());
        if (!$this->tokens->consume($token->id, $token->userId, $now)) {
            throw new ResetLinkUnusable('Lien déjà utilisé.');
        }

        $user->changePassword($this->hasher->hash($command->newPassword), $now);
        $user->verifyEmail($now);
        $this->events->publish(...$user->releaseEvents());
        $this->audit->record(new AuditRecord(
            id: $this->ids->next(),
            occurredAt: $now,
            actorType: 'user',
            actorId: $user->id()->value,
            action: 'identity.password_reset',
            subjectType: 'user',
            subjectId: $user->id()->value,
            ip: $command->ip,
        ));
    }
}
