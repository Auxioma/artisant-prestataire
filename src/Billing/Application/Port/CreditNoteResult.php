<?php

declare(strict_types=1);

namespace App\Billing\Application\Port;

final readonly class CreditNoteResult
{
    public function __construct(public string $id, public ?string $number, public int $amountCents, public int $refundedCents, public ?string $pdfUrl)
    {
    }
}
