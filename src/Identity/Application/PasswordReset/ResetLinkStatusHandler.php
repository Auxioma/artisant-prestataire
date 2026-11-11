<?php

declare(strict_types=1);

namespace App\Identity\Application\PasswordReset;

use App\Identity\Application\EmailVerification\SecretToken;
use App\Identity\Application\Port\PasswordResetTokens;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Domain\Time\Clock;

final readonly class ResetLinkStatusHandler implements QueryHandler
{
    public function __construct(private PasswordResetTokens $tokens, private Clock $clock)
    {
    }

    public function __invoke(ResetLinkStatus $query): ResetLinkState
    {
        $token = SecretToken::isWellFormed($query->token) ? $this->tokens->findByHash(SecretToken::hashOf($query->token)) : null;

        return null !== $token && $token->isUsable($this->clock->now()) ? ResetLinkState::Valid : ResetLinkState::Unusable;
    }
}
