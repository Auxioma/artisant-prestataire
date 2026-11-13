<?php

declare(strict_types=1);

namespace App\Quote\Application\Port;

use App\Quote\Application\Pdf\QuotePdfDocument;

interface QuotePdfRenderer
{
    /** @return string contenu binaire du PDF */
    public function render(QuotePdfDocument $document): string;
}
