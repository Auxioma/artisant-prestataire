<?php

declare(strict_types=1);

namespace App\Administration\Infrastructure\Settings;

use App\Administration\Application\Port\SettingStore;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalSettingStore implements SettingStore
{
    public function __construct(private Connection $connection)
    {
    }

    public function find(SettingKey $key): mixed
    {
        $raw = $this->connection->fetchOne('SELECT value FROM admin.setting WHERE key = :key', ['key' => $key->value]);

        return false === $raw ? null : DbalValue::json($raw);
    }

    /**
     * @return array<string, mixed> valeurs brutes indexées par clé
     */
    public function all(): array
    {
        $values = [];
        foreach ($this->connection->fetchAllKeyValue('SELECT key, value FROM admin.setting') as $key => $json) {
            $values[DbalValue::string($key)] = DbalValue::json($json);
        }

        return $values;
    }

    public function save(SettingKey $key, mixed $value, ?string $adminId, string $reason, \DateTimeImmutable $changedAt): bool
    {
        $after = json_encode($value, \JSON_THROW_ON_ERROR);
        $before = $this->connection->fetchOne('SELECT value FROM admin.setting WHERE key = :key FOR UPDATE', ['key' => $key->value]);
        $before = DbalValue::nullableString($before);
        if (null !== $before && DbalValue::json($before) === $value) {
            return false;
        }

        $this->connection->executeStatement(
            'INSERT INTO admin.setting (key, value, type, updated_by, updated_at) VALUES (:key, :value, :type, :admin, :at)
             ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, type = EXCLUDED.type, updated_by = EXCLUDED.updated_by, updated_at = EXCLUDED.updated_at',
            ['key' => $key->value, 'value' => $after, 'type' => $key->definition()->type->value, 'admin' => $adminId, 'at' => $changedAt->format(\DATE_ATOM)],
        );
        $this->connection->executeStatement(
            'INSERT INTO admin.setting_history (key, value_before, value_after, changed_by, reason, changed_at) VALUES (:key, :before, :after, :admin, :reason, :at)',
            ['key' => $key->value, 'before' => $before, 'after' => $after, 'admin' => $adminId, 'reason' => $reason, 'at' => $changedAt->format(\DATE_ATOM)],
        );

        return true;
    }
}
