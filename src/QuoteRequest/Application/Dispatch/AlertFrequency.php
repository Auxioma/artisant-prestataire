<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Dispatch;

/** PR14.2 — fréquence des alertes de nouvelles demandes choisie par l'artisan (immédiate par défaut). */
enum AlertFrequency: string
{
    case Immediate = 'immediate';
    case TwiceDaily = 'twice_daily';
    case Daily = 'daily';
}
