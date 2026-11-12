<?php

declare(strict_types=1);

namespace App\Identity\Application\EmailVerification;

use App\Identity\Application\Port\EmailTokens;
use App\Identity\Domain\User\UserId;
use App\Identity\Domain\User\UserRepository;
use App\Identity\Domain\User\UserStatus;
use App\Shared\Application\Bus\QueryHandler;

final readonly class PasswordlessLoginHandler implements QueryHandler
{
    public function __construct(private EmailTokens $tokens, private UserRepository $users)
    {
    }

    public function __invoke(PasswordlessLogin $query): ?string
    {
        $token = SecretToken::isWellFormed($query->token) ? $this->tokens->findByHash(SecretToken::hashOf($query->token)) : null;
        if (null === $token || null === $token->usedAt || EmailTokenPurpose::Signup !== $token->purpose) {
            return null;
        }
        $user = $this->users->find(new UserId($token->userId));

        return null !== $user && $user->isPasswordless() && UserStatus::Active === $user->status() ? $user->email()->value : null;
    }
}
