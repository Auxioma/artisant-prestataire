<?php

declare(strict_types=1);

namespace App\Identity\Application\EmailVerification;

use App\Identity\Application\Port\EmailTokens;
use App\Identity\Domain\User\User;
use App\Identity\Domain\User\UserId;
use App\Identity\Domain\User\UserRepository;
use App\Identity\Domain\User\UserStatus;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Domain\Exception\InvalidValue;
use App\Shared\Domain\ValueObject\Email;

final readonly class ResendEmailVerificationHandler implements CommandHandler
{
    public function __construct(private UserRepository $users, private EmailTokens $tokens, private EmailVerificationSender $sender)
    {
    }

    public function __invoke(ResendEmailVerification $command): void
    {
        $user = $this->resolve($command);
        if (null !== $user && UserStatus::PendingEmail === $user->status() && !$user->isEmailVerified()) {
            $this->sender->send($user, EmailTokenPurpose::Signup);
        }
    }

    private function resolve(ResendEmailVerification $command): ?User
    {
        if (null !== $command->userId) {
            return $this->users->find(new UserId($command->userId));
        }
        if (null !== $command->previousToken && SecretToken::isWellFormed($command->previousToken)) {
            $token = $this->tokens->findByHash(SecretToken::hashOf($command->previousToken));

            return null === $token || EmailTokenPurpose::Signup !== $token->purpose ? null : $this->users->find(new UserId($token->userId));
        }
        if (null !== $command->email) {
            try {
                return $this->users->findByEmail(new Email($command->email));
            } catch (InvalidValue) {
                return null;
            }
        }

        return null;
    }
}
