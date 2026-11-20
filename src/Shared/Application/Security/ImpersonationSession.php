<?php

declare(strict_types=1);

namespace App\Shared\Application\Security;

/** Connexion assistée en cours : l'administrateur agit dans la session d'un utilisateur, pour une durée limitée. */
final readonly class ImpersonationSession
{
    /** Durée maximale d'une connexion assistée (ADR-0013, P-146). */
    public const int MAX_MINUTES = 30;

    public function __construct(
        public string $adminId,
        public string $adminName,
        public string $userId,
        public string $returnUrl,
        public \DateTimeImmutable $startedAt,
        public \DateTimeImmutable $expiresAt,
    ) {
    }

    public function expired(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }
}
