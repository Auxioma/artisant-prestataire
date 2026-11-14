<?php

declare(strict_types=1);

namespace App\Billing\Application\Invoice;

use App\Billing\Application\Payment\IdempotencyKey;
use App\Billing\Application\Port\CreditNoteRequest;
use App\Billing\Application\Port\PaymentGateway;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Integration\ExternalServiceUnavailable;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/**
 * §4.3 / ADR-0009 — remboursement depuis le back-office : facture payée, montant ≤ solde non remboursé, motif et
 * commentaire obligatoires ; avoir Stripe remboursé sur le moyen de paiement d'origine, répliqué, journalisé, E-48.
 * Les crédits de devis ne sont pas touchés (geste financier seulement).
 */
final readonly class RefundInvoiceHandler implements CommandHandler
{
    private const int COMMENT_MAX = 500;

    public function __construct(
        private Invoices $invoices,
        private PaymentGateway $gateway,
        private CreditNoteNotifier $notifier,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(RefundInvoice $command): void
    {
        $invoice = $this->invoices->findAny($command->invoiceId) ?? throw new RefundRejected('Facture introuvable.');
        $remaining = $invoice->view->refundableCents();
        if ($remaining <= 0) {
            throw new RefundRejected('Cette facture n’est pas payée ou a déjà été entièrement remboursée.');
        }
        if (!\array_key_exists($command->reason, RefundInvoice::REASONS)) {
            throw new RefundRejected('Choisissez un motif de remboursement.');
        }
        $comment = trim($command->comment);
        if ('' === $comment || mb_strlen($comment) > self::COMMENT_MAX) {
            throw new RefundRejected('Le commentaire est obligatoire (500 caractères au plus).');
        }
        $amount = $command->amountCents ?? $remaining;
        if ($amount <= 0 || $amount > $remaining) {
            throw new RefundRejected('Le montant doit être positif et ne pas dépasser le solde remboursable.');
        }
        $full = $amount === $invoice->view->amountPaidCents && 0 === $invoice->view->refundedCents;
        $id = $this->ids->next();
        try {
            $result = $this->gateway->refundInvoice(new CreditNoteRequest(
                $invoice->stripeInvoiceId,
                $amount,
                $full,
                $command->reason,
                RefundInvoice::REASONS[$command->reason].' — '.$comment,
                ['credit_note_id' => $id, 'admin_id' => $command->adminId],
                IdempotencyKey::for('RefundInvoice', $command->invoiceId, $amount.'|'.$invoice->view->refundedCents),
            ));
        } catch (ExternalServiceUnavailable $e) {
            throw new RefundRejected('Stripe n’a pas pu effectuer le remboursement ('.$e->getMessage().').');
        }
        $now = $this->clock->now();
        $note = new CreditNoteRecord($result->id, $invoice->artisanId, $result->number, $result->amountCents, $result->refundedCents, $command->reason, $comment, $command->adminId, $result->pdfUrl, $invoice->view->number);
        $this->invoices->recordCreditNote($id, $command->invoiceId, $note, $now);
        $this->audit->record(new AuditRecord($this->ids->next(), $now, 'admin', $command->adminId, 'billing.refunded', 'invoice', $command->invoiceId, context: [
            'invoice' => $invoice->view->number,
            'credit_note' => $result->number,
            'amount_cents' => $amount,
            'full' => $full,
            'reason' => $command->reason,
        ]));
        $this->notifier->notify($note, $now);
    }
}
