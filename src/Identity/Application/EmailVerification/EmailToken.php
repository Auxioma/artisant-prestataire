<?php

declare(strict_types=1);

namespace App\Identity\Application\EmailVerification;

/** Jeton stocké (empreinte seulement — le jeton en clair n'existe que dans l'e-mail). */
final readonly class EmailToken
{
    public function __construct(
        public string $id,
        public string $userId,
        public EmailTokenPurpose $purpose,
        public ?string $newEmail,
        public \DateTimeImmutable $createdAt,
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
