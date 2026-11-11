<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Login;

use App\Identity\Application\Port\KnownDevices;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalKnownDevices implements KnownDevices
{
    public function __construct(private Connection $connection)
    {
    }

    public function touch(string $id, string $userId, string $deviceHash, string $description, ?string $ipHash, \DateTimeImmutable $now): bool
    {
        $inserted = $this->connection->executeStatement(
            'INSERT INTO identity.session_device (id, user_id, device_hash, description, ip_hash, first_seen_at, last_seen_at)
             VALUES (:id, :user, :hash, :description, :ip, :now, :now)
             ON CONFLICT (user_id, device_hash) DO UPDATE SET last_seen_at = EXCLUDED.last_seen_at, ip_hash = EXCLUDED.ip_hash, description = EXCLUDED.description',
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
}
