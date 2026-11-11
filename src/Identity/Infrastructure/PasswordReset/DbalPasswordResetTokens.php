<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\PasswordReset;

use App\Identity\Application\PasswordReset\PasswordResetToken;
use App\Identity\Application\Port\PasswordResetTokens;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalPasswordResetTokens implements PasswordResetTokens
{
    public function __construct(private Connection $connection)
    {
    }

    public function issue(string $id, string $userId, string $tokenHash, ?string $ipHash, \DateTimeImmutable $now, \DateTimeImmutable $expiresAt): void
    {
        $this->revokeActive($userId, $now);
        $this->connection->insert('identity.password_reset_token', [
            'id' => $id,
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'requested_ip_hash' => $ipHash,
            'created_at' => $now->format(\DATE_ATOM),
            'expires_at' => $expiresAt->format(\DATE_ATOM),
        ]);
    }

    public function findByHash(string $tokenHash): ?PasswordResetToken
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM identity.password_reset_token WHERE token_hash = :h', ['h' => $tokenHash]);
        if (false === $row) {
            return null;
        }

        return new PasswordResetToken(
            DbalValue::string($row['id']),
            DbalValue::string($row['user_id']),
            new \DateTimeImmutable(DbalValue::string($row['expires_at'])),
            null === $row['used_at'] ? null : new \DateTimeImmutable(DbalValue::string($row['used_at'])),
            null === $row['revoked_at'] ? null : new \DateTimeImmutable(DbalValue::string($row['revoked_at'])),
        );
    }

    public function consume(string $id, string $userId, \DateTimeImmutable $now): bool
    {
        $used = $this->connection->executeStatement(
            'UPDATE identity.password_reset_token SET used_at = :now WHERE id = :id AND used_at IS NULL AND revoked_at IS NULL',
            ['now' => $now->format(\DATE_ATOM), 'id' => $id],
        );
        $this->revokeActive($userId, $now);

        return 1 === $used;
    }

    private function revokeActive(string $userId, \DateTimeImmutable $now): void
    {
        $this->connection->executeStatement(
            'UPDATE identity.password_reset_token SET revoked_at = :now WHERE user_id = :user AND used_at IS NULL AND revoked_at IS NULL',
            ['now' => $now->format(\DATE_ATOM), 'user' => $userId],
        );
    }
}
