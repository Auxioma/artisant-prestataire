<?php

declare(strict_types=1);

namespace App\Billing\Application\Invoice;

final readonly class InvoiceAdminRow
{
    public function __construct(public string $artisanId, public string $stripeInvoiceId, public InvoiceView $view)
    {
    }
}
