<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence;

/** Mise en forme des lignes de l'export RGPD : dates lisibles (heure de Paris), JSON décodé, valeurs scalaires. */
final class PersonalDataRows
{
    private const string DATE_COLUMN = '/(_at|_on|_date|^date)$/';
    private const string TIMEZONE = 'Europe/Paris';

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string>               $jsonColumns
     *
     * @return list<array<string, scalar|array<mixed>|null>>
     */
    public static function format(array $rows, array $jsonColumns = []): array
    {
        $zone = new \DateTimeZone(self::TIMEZONE);

        return array_map(static function (array $row) use ($jsonColumns, $zone): array {
            $out = [];
            foreach ($row as $column => $value) {
                if (\is_string($value) && \in_array($column, $jsonColumns, true)) {
                    $decoded = json_decode($value, true);
                    $out[$column] = \is_array($decoded) ? $decoded : $value;
                } elseif (\is_string($value) && 1 === preg_match(self::DATE_COLUMN, $column) && false !== strtotime($value)) {
                    $out[$column] = new \DateTimeImmutable($value)->setTimezone($zone)->format(\strlen($value) > 10 ? 'd/m/Y H:i' : 'd/m/Y');
                } else {
                    $out[$column] = \is_scalar($value) || null === $value ? $value : (string) json_encode($value);
                }
            }

            return $out;
        }, $rows);
    }
}
