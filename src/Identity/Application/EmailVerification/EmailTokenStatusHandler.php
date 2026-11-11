<?php

declare(strict_types=1);

namespace App\Identity\Application\EmailVerification;

use App\Identity\Application\Port\EmailTokens;
use App\Identity\Domain\User\UserId;
use App\Identity\Domain\User\UserRepository;
use App\Identity\Domain\User\UserStatus;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Domain\Time\Clock;

final readonly class EmailTokenStatusHandler implements QueryHandler
{
    public function __construct(private EmailTokens $tokens, private UserRepository $users, private Clock $clock)
    {
    }

    public function __invoke(EmailTokenStatus $query): EmailTokenState
    {
        if (!SecretToken::isWellFormed($query->token)) {
            return EmailTokenState::Invalid;
        }
        $token = $this->tokens->findByHash(SecretToken::hashOf($query->token));
        $user = null === $token ? null : $this->users->find(new UserId($token->userId));
        if (null === $token || null === $user || UserStatus::Anonymized === $user->status()) {
            return EmailTokenState::Invalid;
        }
        if (null !== $token->usedAt || (EmailTokenPurpose::Signup === $token->purpose && $user->isEmailVerified())) {
            return EmailTokenState::AlreadyVerified;
        }
        if (null !== $token->revokedAt) {
            return EmailTokenState::Invalid;
        }

        return $token->isUsable($this->clock->now()) ? EmailTokenState::Valid : EmailTokenState::Expired;
    }
}
