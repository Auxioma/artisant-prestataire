<?php

declare(strict_types=1);

namespace App\Shared\Application\Settings;

/**
 * Port de lecture des paramètres administrables (ADR-0007). Les handlers lisent ici et passent les valeurs au domaine.
 */
interface SettingsProvider
{
    public function int(SettingKey $key): int;

    public function nullableInt(SettingKey $key): ?int;

    public function bool(SettingKey $key): bool;

    public function choice(SettingKey $key): string;

    public function nullableDecimal(SettingKey $key): ?float;

    /** @return list<int> */
    public function intList(SettingKey $key): array;

    /** @return list<string> */
    public function stringList(SettingKey $key): array;

    /** @return array<string, float> */
    public function weights(SettingKey $key): array;

    public function nullableUrl(SettingKey $key): ?string;
}
