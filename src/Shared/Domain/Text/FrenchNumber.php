<?php

declare(strict_types=1);

namespace App\Shared\Domain\Text;

/** Montants et nombres au format français (espaces fines insécables, virgule décimale). */
final class FrenchNumber
{
    private const string THOUSANDS = "\u{202f}";
    private const int CENTS = 100;
    private const int MONEY_DECIMALS = 2;

    /** Centimes → « 1 234,56 € » (ou sans symbole). */
    public static function money(int $cents, bool $symbol = true): string
    {
        $value = number_format(abs($cents) / self::CENTS, self::MONEY_DECIMALS, ',', self::THOUSANDS);

        return ($cents < 0 ? '−' : '').$value.($symbol ? "\u{a0}€" : '');
    }

    /** Valeur entière à échelle fixe → « 2,5 » (sans zéros inutiles). */
    public static function decimal(int $value, int $decimals): string
    {
        $text = number_format($value / 10 ** $decimals, $decimals, ',', '');

        return str_contains($text, ',') ? rtrim(rtrim($text, '0'), ',') : $text;
    }
}
