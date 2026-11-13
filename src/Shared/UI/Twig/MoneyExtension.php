<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Montants et nombres au format français : `tm_money` (centimes → « 1 234,56 € »), `tm_decimal` (valeur entière à
 * échelle fixe → « 2,5 », sans zéros inutiles). Espaces insécables fines comme séparateur de milliers.
 */
final class MoneyExtension extends AbstractExtension
{
    private const string THOUSANDS = "\u{202f}";

    public function getFilters(): array
    {
        return [new TwigFilter('tm_money', self::money(...)), new TwigFilter('tm_decimal', self::decimal(...))];
    }

    public static function money(int $cents, bool $symbol = true): string
    {
        $value = number_format(abs($cents) / 100, 2, ',', self::THOUSANDS);

        return ($cents < 0 ? '−' : '').$value.($symbol ? "\u{a0}€" : '');
    }

    /** `tm_decimal(2500, 3)` → « 2,5 » ; `tm_decimal(550, 2)` → « 5,5 ». */
    public static function decimal(int $value, int $decimals): string
    {
        $text = number_format($value / 10 ** $decimals, $decimals, ',', '');

        return str_contains($text, ',') ? rtrim(rtrim($text, '0'), ',') : $text;
    }
}
