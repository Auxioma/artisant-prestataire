<?php

declare(strict_types=1);

namespace App\Identity\Application\PasswordReset;

final readonly class PasswordResetToken
{
    public function __construct(
        public string $id,
        public string $userId,
        public \DateTimeImmutable $expiresAt,
        public ?\DateTimeImmutable $usedAt,
        public ?\DateTimeImmutable $revokedAt,
    ) {
    }

    public function isUsable(\DateTimeImmutable $now): bool
    {
        return null === $this->usedAt && null === $this->revokedAt && $now < $this->expiresAt;
    }
}
