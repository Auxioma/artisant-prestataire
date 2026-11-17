<?php

declare(strict_types=1);

namespace App\Identity\Application\Port;

use App\Identity\Application\PasswordReset\PasswordResetToken;

interface PasswordResetTokens
{
    /** Révoque les liens encore valides du compte, puis enregistre le nouveau. */
    public function issue(string $id, string $userId, string $tokenHash, ?string $ipHash, \DateTimeImmutable $now, \DateTimeImmutable $expiresAt): void;

    public function findByHash(string $tokenHash): ?PasswordResetToken;

    /** Consomme le lien (et révoque les autres liens du compte). Faux si déjà utilisé ou révoqué entre-temps. */
    public function consume(string $id, string $userId, \DateTimeImmutable $now): bool;
}
