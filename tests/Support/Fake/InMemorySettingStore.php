<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\Administration\Application\Port\SettingStore;
use App\Shared\Application\Settings\SettingKey;

final class InMemorySettingStore implements SettingStore
{
    /** @var array<string, mixed> */
    public array $values = [];
    /** @var list<array{key: string, before: mixed, after: mixed, admin: ?string, reason: string}> */
    public array $history = [];

    public function find(SettingKey $key): mixed
    {
        return $this->values[$key->value] ?? null;
    }

    public function all(): array
    {
        return $this->values;
    }

    public function save(SettingKey $key, mixed $value, ?string $adminId, string $reason, \DateTimeImmutable $changedAt): bool
    {
        $before = $this->values[$key->value] ?? null;
        if (\array_key_exists($key->value, $this->values) && $before === $value) {
            return false;
        }
        $this->values[$key->value] = $value;
        $this->history[] = ['key' => $key->value, 'before' => $before, 'after' => $value, 'admin' => $adminId, 'reason' => $reason];

        return true;
    }
}
