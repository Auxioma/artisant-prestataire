<?php

declare(strict_types=1);

namespace App\Quote\Application\Port;

/** Stockage privé des PDF figés (zone `documents`, jamais public, ADR-0008). */
interface QuotePdfStore
{
    public function put(string $key, string $bytes): void;

    public function get(string $key): string;
}
