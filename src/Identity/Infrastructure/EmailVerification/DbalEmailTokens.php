<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\EmailVerification;

use App\Identity\Application\EmailVerification\EmailToken;
use App\Identity\Application\EmailVerification\EmailTokenPurpose;
use App\Identity\Application\Port\EmailTokens;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalEmailTokens implements EmailTokens
{
    public function __construct(private Connection $connection)
    {
    }

    public function issue(string $id, string $userId, EmailTokenPurpose $purpose, ?string $newEmail, string $tokenHash, \DateTimeImmutable $now, \DateTimeImmutable $expiresAt): void
    {
        $this->connection->executeStatement(
            'UPDATE identity.email_verification_token SET revoked_at = :now WHERE user_id = :user AND purpose = :purpose AND used_at IS NULL AND revoked_at IS NULL',
            ['now' => $now->format(\DATE_ATOM), 'user' => $userId, 'purpose' => $purpose->value],
        );
        $this->connection->insert('identity.email_verification_token', [
            'id' => $id,
            'user_id' => $userId,
            'purpose' => $purpose->value,
            'new_email' => $newEmail,
            'token_hash' => $tokenHash,
            'created_at' => $now->format(\DATE_ATOM),
            'expires_at' => $expiresAt->format(\DATE_ATOM),
        ]);
    }

    public function findByHash(string $tokenHash): ?EmailToken
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM identity.email_verification_token WHERE token_hash = :h', ['h' => $tokenHash]);
        if (false === $row) {
            return null;
        }

        return new EmailToken(
            DbalValue::string($row['id']),
            DbalValue::string($row['user_id']),
            EmailTokenPurpose::from(DbalValue::string($row['purpose'])),
            null === $row['new_email'] ? null : DbalValue::string($row['new_email']),
            new \DateTimeImmutable(DbalValue::string($row['created_at'])),
            new \DateTimeImmutable(DbalValue::string($row['expires_at'])),
            null === $row['used_at'] ? null : new \DateTimeImmutable(DbalValue::string($row['used_at'])),
            null === $row['revoked_at'] ? null : new \DateTimeImmutable(DbalValue::string($row['revoked_at'])),
        );
    }

    public function lastIssuedAt(string $userId, EmailTokenPurpose $purpose): ?\DateTimeImmutable
    {
        $value = $this->connection->fetchOne(
            'SELECT max(created_at) FROM identity.email_verification_token WHERE user_id = :user AND purpose = :purpose',
            ['user' => $userId, 'purpose' => $purpose->value],
        );

        return \is_string($value) ? new \DateTimeImmutable($value) : null;
    }

    public function markUsed(string $id, \DateTimeImmutable $now): void
    {
        $updated = $this->connection->executeStatement(
            'UPDATE identity.email_verification_token SET used_at = :now WHERE id = :id AND used_at IS NULL AND revoked_at IS NULL',
            ['now' => $now->format(\DATE_ATOM), 'id' => $id],
        );
        if (1 !== $updated) {
            throw new \LogicException('Jeton déjà utilisé ou révoqué.'); // deux clics simultanés : la transaction est annulée
        }
    }
}
