<?php

declare(strict_types=1);

namespace App\Shared\Application\Settings;

/**
 * Définition d'un paramètre administrable : type, valeur par défaut (annexe G / annexe I), bornes de validation.
 */
final readonly class SettingDefinition
{
    /**
     * @param list<string>|null $choices    valeurs autorisées (Choice), éléments autorisés (StringList), critères (WeightMap) ou domaines autorisés (NullableUrl)
     * @param bool              $exhaustive StringList : chaque élément de $choices doit figurer (ex. ordre de consommation)
     */
    public function __construct(
        public SettingType $type,
        public mixed $default,
        public string $label,
        public ?int $min = null,
        public ?int $max = null,
        public ?array $choices = null,
        public bool $proposed = false,
        public bool $exhaustive = false,
    ) {
    }

    /**
     * Valide et normalise une valeur brute (saisie BO ou JSON stocké).
     *
     * @return int|bool|string|float|list<int>|list<string>|array<string, float>|null
     */
    public function normalize(SettingKey $key, mixed $raw): int|bool|string|float|array|null
    {
        return match ($this->type) {
            SettingType::Integer => $this->integer($key, $raw),
            SettingType::NullableInteger => null === $raw || '' === $raw ? null : $this->integer($key, $raw),
            SettingType::Boolean => $this->boolean($key, $raw),
            SettingType::IntegerList => $this->integerList($key, $raw),
            SettingType::StringList => $this->stringList($key, $raw),
            SettingType::Choice => $this->choice($key, $raw),
            SettingType::NullableDecimal => $this->nullableDecimal($key, $raw),
            SettingType::WeightMap => $this->weightMap($key, $raw),
            SettingType::NullableUrl => $this->nullableUrl($key, $raw),
        };
    }

    private function integer(SettingKey $key, mixed $raw): int
    {
        $value = filter_var($raw, \FILTER_VALIDATE_INT);
        if (\is_bool($raw) || false === $value) {
            throw InvalidSettingValue::for($key, 'entier attendu');
        }
        $this->assertBounds($key, $value);

        return $value;
    }

    private function boolean(SettingKey $key, mixed $raw): bool
    {
        $value = filter_var($raw, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE);
        if (null === $value) {
            throw InvalidSettingValue::for($key, 'booléen attendu');
        }

        return $value;
    }

    /** @return list<int> */
    private function integerList(SettingKey $key, mixed $raw): array
    {
        if (!\is_array($raw) || !array_is_list($raw)) {
            throw InvalidSettingValue::for($key, 'liste d\'entiers attendue');
        }
        $values = array_map(fn (mixed $item): int => $this->integer($key, $item), $raw);
        if (\count($values) !== \count(array_unique($values))) {
            throw InvalidSettingValue::for($key, 'doublons interdits');
        }
        sort($values);

        return $values;
    }

    /** @return list<string> */
    private function stringList(SettingKey $key, mixed $raw): array
    {
        if (!\is_array($raw) || !array_is_list($raw)) {
            throw InvalidSettingValue::for($key, 'liste de chaînes attendue');
        }
        $values = [];
        foreach ($raw as $item) {
            if (!\is_string($item) || '' === trim($item)) {
                throw InvalidSettingValue::for($key, 'chaîne non vide attendue');
            }
            if (null !== $this->choices && !\in_array($item, $this->choices, true)) {
                throw InvalidSettingValue::for($key, \sprintf('« %s » non autorisé', $item));
            }
            $values[] = trim($item);
        }
        if (\count($values) !== \count(array_unique($values))) {
            throw InvalidSettingValue::for($key, 'doublons interdits');
        }
        if ($this->exhaustive && null !== $this->choices && [] !== array_diff($this->choices, $values)) {
            throw InvalidSettingValue::for($key, 'tous les éléments sont requis : '.implode(', ', $this->choices));
        }

        return $values;
    }

    private function choice(SettingKey $key, mixed $raw): string
    {
        if (!\is_string($raw) || null === $this->choices || !\in_array($raw, $this->choices, true)) {
            throw InvalidSettingValue::for($key, 'valeur hors liste');
        }

        return $raw;
    }

    private function nullableDecimal(SettingKey $key, mixed $raw): ?float
    {
        if (null === $raw || '' === $raw) {
            return null;
        }
        $value = filter_var($raw, \FILTER_VALIDATE_FLOAT);
        if (\is_bool($raw) || false === $value) {
            throw InvalidSettingValue::for($key, 'nombre attendu');
        }

        return $value;
    }

    /** @return array<string, float> */
    private function weightMap(SettingKey $key, mixed $raw): array
    {
        if (!\is_array($raw) || null === $this->choices) {
            throw InvalidSettingValue::for($key, 'table de pondérations attendue');
        }
        $missing = array_diff($this->choices, array_keys($raw));
        $unknown = array_diff(array_keys($raw), $this->choices);
        if ([] !== $missing || [] !== $unknown) {
            throw InvalidSettingValue::for($key, 'critères attendus : '.implode(', ', $this->choices));
        }
        $weights = [];
        foreach ($this->choices as $criterion) {
            $weight = filter_var($raw[$criterion], \FILTER_VALIDATE_FLOAT);
            if (false === $weight || $weight < 0.0) {
                throw InvalidSettingValue::for($key, \sprintf('poids « %s » positif attendu', $criterion));
            }
            $weights[$criterion] = $weight;
        }
        if (abs(array_sum($weights) - 1.0) > 0.0001) {
            throw InvalidSettingValue::for($key, 'la somme des poids doit valoir 1');
        }

        return $weights;
    }

    /**
     * URL HTTPS dont l'hôte appartient aux domaines autorisés (anti-redirection vers un site tiers depuis le BO).
     */
    private function nullableUrl(SettingKey $key, mixed $raw): ?string
    {
        if (null === $raw || '' === $raw) {
            return null;
        }
        if (!\is_string($raw) || false === filter_var($raw, \FILTER_VALIDATE_URL) || !str_starts_with($raw, 'https://')) {
            throw InvalidSettingValue::for($key, 'URL https:// attendue');
        }
        $host = strtolower((string) parse_url($raw, \PHP_URL_HOST));
        foreach ($this->choices ?? [] as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return $raw;
            }
        }

        throw InvalidSettingValue::for($key, 'domaine non autorisé ('.implode(', ', $this->choices ?? []).')');
    }

    private function assertBounds(SettingKey $key, int $value): void
    {
        if (null !== $this->min && $value < $this->min) {
            throw InvalidSettingValue::for($key, \sprintf('minimum %d', $this->min));
        }
        if (null !== $this->max && $value > $this->max) {
            throw InvalidSettingValue::for($key, \sprintf('maximum %d', $this->max));
        }
    }
}
