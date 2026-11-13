<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig;

use App\Shared\Domain\Text\FrenchNumber;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/** `tm_money` (centimes → « 1 234,56 € ») et `tm_decimal` (échelle fixe → « 2,5 ») : voir FrenchNumber. */
final class MoneyExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [new TwigFilter('tm_money', FrenchNumber::money(...)), new TwigFilter('tm_decimal', FrenchNumber::decimal(...))];
    }

    public static function money(int $cents, bool $symbol = true): string
    {
        return FrenchNumber::money($cents, $symbol);
    }
}
