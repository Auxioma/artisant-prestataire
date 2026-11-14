<?php

declare(strict_types=1);

namespace App\Billing\Application\Invoice;

use App\Billing\Application\Subscription\SubscriptionMailer;
use App\Billing\Application\Subscription\SubscriptionReader;

/** E-48 — remboursement effectué / avoir, une seule fois par avoir (action du back-office ou webhook Stripe). */
final readonly class CreditNoteNotifier
{
    public function __construct(private Invoices $invoices, private SubscriptionReader $reader, private SubscriptionMailer $mailer)
    {
    }

    public function notify(CreditNoteRecord $note, \DateTimeImmutable $at): void
    {
        if (!$this->invoices->markCreditNoteNotified($note->stripeCreditNoteId, $at)) {
            return;
        }
        $userId = $this->reader->userOfArtisan($note->artisanId);
        if (null !== $userId) {
            $this->mailer->refunded($userId, $note->invoiceNumber, $note->number, $note->refundedCents > 0 ? $note->refundedCents : $note->amountCents, $note->pdfUrl);
        }
    }
}
