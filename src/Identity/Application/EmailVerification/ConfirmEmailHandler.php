<?php

declare(strict_types=1);

namespace App\Identity\Application\EmailVerification;

use App\Identity\Application\Port\EmailTokens;
use App\Identity\Application\Port\IdentityMailer;
use App\Identity\Domain\User\UserId;
use App\Identity\Domain\User\UserRepository;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Event\Integration\AccountEmailVerified;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;
use App\Shared\Domain\ValueObject\Email;

final readonly class ConfirmEmailHandler implements CommandHandler
{
    public function __construct(
        private EmailTokens $tokens,
        private UserRepository $users,
        private IdentityMailer $mailer,
        private EventBus $events,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(ConfirmEmail $command): void
    {
        $now = $this->clock->now();
        $token = SecretToken::isWellFormed($command->token) ? $this->tokens->findByHash(SecretToken::hashOf($command->token)) : null;
        if (null === $token) {
            throw new InvalidEmailToken(EmailTokenState::Invalid);
        }
        if (!$token->isUsable($now)) {
            throw new InvalidEmailToken(null !== $token->usedAt ? EmailTokenState::AlreadyVerified : EmailTokenState::Expired);
        }
        $user = $this->users->get(new UserId($token->userId));
        $verified = false;

        if (EmailTokenPurpose::EmailChange === $token->purpose && null !== $token->newEmail) {
            $newEmail = new Email($token->newEmail);
            $owner = $this->users->findByEmail($newEmail);
            if (null !== $owner && !$owner->id()->equals($user->id())) {
                // Adresse prise entre la demande et la confirmation : lien inutilisable.
                throw new InvalidEmailToken(EmailTokenState::Invalid);
            }
            $oldEmail = $user->email();
            $user->changeEmail($newEmail, $now);
            $this->mailer->sendEmailChangedAlert($oldEmail, $user->firstName(), $now);
        } else {
            $user->verifyEmail($now);
            $verified = true;
        }
        $this->tokens->markUsed($token->id, $now);
        $this->events->publish(...$user->releaseEvents());
        if ($verified) {
            $this->events->publish(new AccountEmailVerified($user->id()->value, $now));
        }
        $this->audit->record(new AuditRecord(
            id: $this->ids->next(),
            occurredAt: $now,
            actorType: 'user',
            actorId: $user->id()->value,
            action: EmailTokenPurpose::EmailChange === $token->purpose ? 'identity.email_changed' : 'identity.email_verified',
            subjectType: 'user',
            subjectId: $user->id()->value,
            ip: $command->ip,
        ));
    }
}
