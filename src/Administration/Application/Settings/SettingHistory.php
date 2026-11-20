<?php

declare(strict_types=1);

namespace App\Administration\Application\Settings;

/** Journal des modifications de paramètres (`admin.setting_history`), valeurs avant / après. */
interface SettingHistory
{
    /**
     * @param list<string> $keys
     *
     * @return list<array{at: \DateTimeImmutable, admin: ?string, key: string, before: mixed, after: mixed}>
     */
    public function latest(array $keys, int $limit): array;
}
