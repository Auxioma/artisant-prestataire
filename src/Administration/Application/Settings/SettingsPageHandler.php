<?php

declare(strict_types=1);

namespace App\Administration\Application\Settings;

use App\Administration\Application\Port\SettingStore;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Application\Settings\SettingKey;

final readonly class SettingsPageHandler implements QueryHandler
{
    private const int HISTORY = 15;

    public function __construct(private SettingStore $store, private SettingHistory $history)
    {
    }

    public function __invoke(SettingsPage $query): ?SettingsPageView
    {
        if (!\array_key_exists($query->rubric, SettingRubric::RUBRICS)) {
            return null;
        }
        $keys = SettingRubric::keys($query->rubric);
        $stored = $this->store->all();
        $fields = array_map(static function (SettingKey $key) use ($stored): array {
            $d = $key->definition();

            return ['key' => $key->value, 'label' => $d->label, 'type' => $d->type->value, 'value' => \array_key_exists($key->value, $stored) ? $stored[$key->value] : $d->default, 'default' => $d->default, 'min' => $d->min, 'max' => $d->max, 'choices' => $d->choices, 'proposed' => $d->proposed];
        }, $keys);

        return new SettingsPageView($query->rubric, SettingRubric::RUBRICS[$query->rubric][0], $fields, [] === $keys ? [] : $this->history->latest(array_map(static fn (SettingKey $k): string => $k->value, $keys), self::HISTORY));
    }
}
