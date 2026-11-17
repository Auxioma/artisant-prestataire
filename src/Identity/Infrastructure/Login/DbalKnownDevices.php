<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Login;

use App\Identity\Application\Port\KnownDevices;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalKnownDevices implements KnownDevices
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(private Connection $connection)
    {
    }

    public function touch(string $id, string $userId, string $deviceHash, string $description, ?string $ipHash, \DateTimeImmutable $now): bool
    {
        $inserted = $this->connection->executeStatement(
            'INSERT INTO identity.session_device (id, user_id, device_hash, description, ip_hash, first_seen_at, last_seen_at)
             VALUES (:id, :user, :hash, :description, :ip, :now, :now)
             ON CONFLICT (user_id, device_hash) DO UPDATE SET last_seen_at = EXCLUDED.last_seen_at, ip_hash = EXCLUDED.ip_hash, description = EXCLUDED.description, revoked_at = NULL',
            ['id' => $id, 'user' => $userId, 'hash' => $deviceHash, 'description' => mb_substr($description, 0, 80), 'ip' => $ipHash, 'now' => $now->format(\DATE_ATOM)],
        );

        // En cas de conflit, la ligne existante garde son identifiant : le nôtre n'existe que si elle vient d'être insérée.
        return 1 === $inserted && 1 === DbalValue::int($this->connection->fetchOne(
            'SELECT count(*) FROM identity.session_device WHERE id = :id',
            ['id' => $id],
        ));
    }

    public function count(string $userId): int
    {
        return DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM identity.session_device WHERE user_id = :u AND revoked_at IS NULL', ['u' => $userId]));
    }

    public function active(string $userId): array
    {
        if (1 !== preg_match(self::UUID, $userId)) {
            return [];
        }

        return array_map(static fn (array $r): array => [
            'id' => DbalValue::string($r['id']),
            'hash' => DbalValue::string($r['device_hash']),
            'description' => DbalValue::string($r['description']),
            'firstSeen' => new \DateTimeImmutable(DbalValue::string($r['first_seen_at'])),
            'lastSeen' => new \DateTimeImmutable(DbalValue::string($r['last_seen_at'])),
        ], $this->connection->fetchAllAssociative(
            'SELECT id, device_hash, description, first_seen_at, last_seen_at FROM identity.session_device WHERE user_id = :u AND revoked_at IS NULL ORDER BY last_seen_at DESC, id',
            ['u' => $userId],
        ));
    }

    public function revoke(string $userId, ?string $deviceId, ?string $keepHash, \DateTimeImmutable $at): int
    {
        if (null !== $deviceId) {
            if (1 !== preg_match(self::UUID, $deviceId)) {
                return 0;
            }

            return (int) $this->connection->executeStatement(
                'UPDATE identity.session_device SET revoked_at = :at WHERE user_id = :u AND id = :id AND revoked_at IS NULL',
                ['at' => $at->format(\DATE_ATOM), 'u' => $userId, 'id' => $deviceId],
            );
        }

        return (int) $this->connection->executeStatement(
            'UPDATE identity.session_device SET revoked_at = :at WHERE user_id = :u AND revoked_at IS NULL AND device_hash IS DISTINCT FROM :keep',
            ['at' => $at->format(\DATE_ATOM), 'u' => $userId, 'keep' => $keepHash],
        );
    }

    public function isRevoked(string $userId, string $deviceHash): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM identity.session_device WHERE user_id = :u AND device_hash = :h AND revoked_at IS NOT NULL',
            ['u' => $userId, 'h' => $deviceHash],
        );
    }

    public function forget(string $userId): void
    {
        $this->connection->executeStatement('DELETE FROM identity.session_device WHERE user_id = :u', ['u' => $userId]);
    }
}
