<?php

declare(strict_types=1);

namespace App\Quote\Domain\Quote;

/** PR04.2 — taux de TVA en points de base (2 000 = 20 %). */
enum VatRate: int
{
    case Standard = 2000;
    case Intermediate = 1000;
    case Reduced = 550;
    case Zero = 0;

    public function label(): string
    {
        return match ($this) {
            self::Standard => '20 %',
            self::Intermediate => '10 %',
            self::Reduced => '5,5 %',
            self::Zero => '0 %',
        };
    }

    /** Taux réduits : attestation du client requise (rappel informatif, PR04.2). */
    public function needsClientCertificate(): bool
    {
        return \in_array($this, [self::Intermediate, self::Reduced], true);
    }
}
