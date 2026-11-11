<?php

declare(strict_types=1);

namespace App\Identity\Application\Port;

use App\Identity\Application\EmailVerification\EmailToken;
use App\Identity\Application\EmailVerification\EmailTokenPurpose;

interface EmailTokens
{
    /** Révoque les jetons encore valides de même usage pour ce compte, puis enregistre le nouveau. */
    public function issue(string $id, string $userId, EmailTokenPurpose $purpose, ?string $newEmail, string $tokenHash, \DateTimeImmutable $now, \DateTimeImmutable $expiresAt): void;

    public function findByHash(string $tokenHash): ?EmailToken;

    public function lastIssuedAt(string $userId, EmailTokenPurpose $purpose): ?\DateTimeImmutable;

    public function markUsed(string $id, \DateTimeImmutable $now): void;
}
