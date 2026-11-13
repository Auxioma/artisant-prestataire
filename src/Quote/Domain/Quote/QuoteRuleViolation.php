<?php

declare(strict_types=1);

namespace App\Quote\Domain\Quote;

/** Règle métier d'un devis enfreinte (message affichable à l'artisan ou au client). */
final class QuoteRuleViolation extends \DomainException
{
}
