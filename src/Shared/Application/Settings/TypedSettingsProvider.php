<?php

declare(strict_types=1);

namespace App\Shared\Application\Settings;

/**
 * Accesseurs typés communs : contrôle que le type demandé correspond à la définition de la clé.
 */
abstract class TypedSettingsProvider implements SettingsProvider
{
    /**
     * Valeur courante déjà normalisée (stockée ou, à défaut, valeur par défaut de la définition).
     *
     * @return int|bool|string|float|list<int>|list<string>|array<string, float>|null
     */
    abstract protected function value(SettingKey $key): int|bool|string|float|array|null;

    final public function int(SettingKey $key): int
    {
        $value = $this->typed($key, SettingType::Integer);
        \assert(\is_int($value));

        return $value;
    }

    final public function nullableInt(SettingKey $key): ?int
    {
        $value = $this->typed($key, SettingType::NullableInteger);
        \assert(null === $value || \is_int($value));

        return $value;
    }

    final public function bool(SettingKey $key): bool
    {
        $value = $this->typed($key, SettingType::Boolean);
        \assert(\is_bool($value));

        return $value;
    }

    final public function choice(SettingKey $key): string
    {
        $value = $this->typed($key, SettingType::Choice);
        \assert(\is_string($value));

        return $value;
    }

    final public function nullableDecimal(SettingKey $key): ?float
    {
        $value = $this->typed($key, SettingType::NullableDecimal);
        \assert(null === $value || \is_float($value));

        return $value;
    }

    final public function intList(SettingKey $key): array
    {
        $value = $this->typed($key, SettingType::IntegerList);
        \assert(\is_array($value));

        /** @var list<int> $value */
        return $value;
    }

    final public function stringList(SettingKey $key): array
    {
        $value = $this->typed($key, SettingType::StringList);
        \assert(\is_array($value));

        /** @var list<string> $value */
        return $value;
    }

    final public function weights(SettingKey $key): array
    {
        $value = $this->typed($key, SettingType::WeightMap);
        \assert(\is_array($value));

        /** @var array<string, float> $value */
        return $value;
    }

    final public function nullableUrl(SettingKey $key): ?string
    {
        $value = $this->typed($key, SettingType::NullableUrl);
        \assert(null === $value || \is_string($value));

        return $value;
    }

    /**
     * @return int|bool|string|float|list<int>|list<string>|array<string, float>|null
     */
    private function typed(SettingKey $key, SettingType $expected): int|bool|string|float|array|null
    {
        $actual = $key->definition()->type;
        if ($actual !== $expected) {
            throw new \LogicException(\sprintf('Le paramètre « %s » est de type %s, pas %s.', $key->value, $actual->value, $expected->value));
        }

        return $this->value($key);
    }
}
