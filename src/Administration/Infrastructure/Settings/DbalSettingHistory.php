<?php

declare(strict_types=1);

namespace App\Administration\Infrastructure\Settings;

use App\Administration\Application\Settings\SettingHistory;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class DbalSettingHistory implements SettingHistory
{
    public function __construct(private Connection $connection)
    {
    }

    public function latest(array $keys, int $limit): array
    {
        return array_map(static fn (array $r): array => [
            'at' => new \DateTimeImmutable(DbalValue::string($r['changed_at'])),
            'admin' => DbalValue::nullableString($r['name']),
            'key' => DbalValue::string($r['key']),
            'before' => null === $r['value_before'] ? null : DbalValue::json($r['value_before']),
            'after' => DbalValue::json($r['value_after']),
        ], $this->connection->fetchAllAssociative(
            'SELECT h.key, h.value_before, h.value_after, h.changed_at, a.name FROM admin.setting_history h LEFT JOIN admin.admin_user a ON a.id = h.changed_by WHERE h.key IN (:keys) ORDER BY h.changed_at DESC, h.id DESC LIMIT :l',
            ['keys' => $keys, 'l' => $limit],
            ['keys' => ArrayParameterType::STRING, 'l' => ParameterType::INTEGER],
        ));
    }
}
