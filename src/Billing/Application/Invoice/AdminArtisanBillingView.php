<?php

declare(strict_types=1);

namespace App\Billing\Application\Invoice;

use App\Billing\Application\Subscription\SubscriptionSummary;

final readonly class AdminArtisanBillingView
{
    /**
     * @param list<InvoiceView>      $invoices
     * @param list<CreditNoteRecord> $creditNotes
     */
    public function __construct(
        public string $artisanId,
        public ?string $name,
        public ?string $email,
        public ?SubscriptionSummary $subscription,
        public array $invoices,
        public array $creditNotes,
    ) {
    }
}
