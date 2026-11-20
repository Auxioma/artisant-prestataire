<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\TypedSettingsProvider;

/** Paramètres en mémoire pour les tests : défauts du catalogue, surchargeables. */
final class InMemorySettingsProvider extends TypedSettingsProvider
{
    /** @var array<string, mixed> */
    private array $overrides = [];

    public function set(SettingKey $key, mixed $value): self
    {
        $this->overrides[$key->value] = $key->definition()->normalize($key, $value);

        return $this;
    }

    protected function value(SettingKey $key): int|bool|string|float|array|null
    {
        $definition = $key->definition();

        return $definition->normalize($key, $this->overrides[$key->value] ?? $definition->default);
    }
}
