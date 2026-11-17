<?php

declare(strict_types=1);

namespace App\Quote\Domain\Quote;

/** PR04.4 — PDF figé et horodaté d'une version envoyée : clé de stockage privé et empreinte SHA-256 (ADR-0008). */
final readonly class QuotePdf
{
    public function __construct(public string $key, public string $sha256)
    {
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $sha256) || '' === trim($key)) {
            throw new QuoteRuleViolation('Empreinte du PDF invalide.');
        }
    }
}
