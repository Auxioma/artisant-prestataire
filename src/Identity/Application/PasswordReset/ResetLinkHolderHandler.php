<?php

declare(strict_types=1);

namespace App\Identity\Application\PasswordReset;

use App\Identity\Application\EmailVerification\SecretToken;
use App\Identity\Application\Port\PasswordResetTokens;
use App\Identity\Domain\User\UserId;
use App\Identity\Domain\User\UserRepository;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Domain\Time\Clock;

final readonly class ResetLinkHolderHandler implements QueryHandler
{
    public function __construct(private PasswordResetTokens $tokens, private UserRepository $users, private Clock $clock)
    {
    }

    public function __invoke(ResetLinkHolder $query): ?string
    {
        $token = SecretToken::isWellFormed($query->token) ? $this->tokens->findByHash(SecretToken::hashOf($query->token)) : null;
        if (null === $token || !$token->isUsable($this->clock->now())) {
            return null;
        }

        return $this->users->find(new UserId($token->userId))?->email()->value;
    }
}
