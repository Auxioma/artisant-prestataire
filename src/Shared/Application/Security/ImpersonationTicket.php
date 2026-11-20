<?php

declare(strict_types=1);

namespace App\Shared\Application\Security;

/** Autorisation à usage unique, émise par le back-office, d'ouvrir la session d'un utilisateur (ADR-0013). */
final readonly class ImpersonationTicket
{
    public function __construct(
        public string $adminId,
        public string $adminName,
        public string $userId,
        public string $reason,
        public string $returnUrl,
        public \DateTimeImmutable $issuedAt,
    ) {
    }
}
