<?php

declare(strict_types=1);

namespace App\QuoteRequest\Domain\Request;

/** P11.3 — budget estimé par tranches (optionnel). */
enum BudgetRange: string
{
    case Under500 = 'lt_500';
    case From500To1000 = '500_1000';
    case From1000To5000 = '1000_5000';
    case From5000To15000 = '5000_15000';
    case Over15000 = 'gt_15000';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Under500 => 'Moins de 500 €',
            self::From500To1000 => 'Entre 500 € et 1 000 €',
            self::From1000To5000 => 'Entre 1 000 € et 5 000 €',
            self::From5000To15000 => 'Entre 5 000 € et 15 000 €',
            self::Over15000 => 'Plus de 15 000 €',
            self::Unknown => 'Je ne sais pas',
        };
    }
}
