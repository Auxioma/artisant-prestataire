<?php

declare(strict_types=1);

namespace App\Billing\Application\Invoice;

/** Copie privée des PDF de factures (conservation 10 ans, §11) — stockage objet chiffré au repos. */
interface InvoiceArchive
{
    public function put(string $key, string $bytes): void;

    public function get(string $key): string;
}
