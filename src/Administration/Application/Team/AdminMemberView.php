<?php

declare(strict_types=1);

namespace App\Administration\Application\Team;

final readonly class AdminMemberView
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public string $role,
        public string $roleLabel,
        public string $status,
        public string $statusLabel,
        public bool $twoFactorEnabled,
        public int $backupCodesLeft,
        public ?\DateTimeImmutable $lastLoginAt,
        public ?\DateTimeImmutable $invitationExpiresAt,
    ) {
    }
}
