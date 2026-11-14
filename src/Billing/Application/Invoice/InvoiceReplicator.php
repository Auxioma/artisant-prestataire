<?php

declare(strict_types=1);

namespace App\Billing\Application\Invoice;

use App\Billing\Application\Port\PaymentGateway;
use App\Billing\Application\Webhook\StripeEvent;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/**
 * ADR-0009 — chaque événement de facture met à jour la réplique locale ; une facture payée est archivée (PDF + empreinte
 * SHA-256, une seule fois). Le moyen de paiement par défaut est relu chez Stripe à chaque changement (PR13.2).
 */
final readonly class InvoiceReplicator
{
    private const array STATUSES = ['draft', 'open', 'paid', 'uncollectible', 'void'];

    public function __construct(
        private Invoices $invoices,
        private InvoiceArchive $archive,
        private PaymentGateway $gateway,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    /** @return bool faux si la facture n'appartient à aucun artisan de la plateforme */
    public function replicate(StripeEvent $event): bool
    {
        $record = self::record($event);
        $artisanId = null === $record ? null : $this->invoices->artisanOfCustomer($record->customerId);
        if (null === $record || null === $artisanId) {
            return false;
        }
        $now = $this->clock->now();
        $invoiceId = $this->invoices->upsert($this->ids->next(), $artisanId, $record, $now);
        if ('paid' === $record->status && null !== $record->pdfUrl && !$this->invoices->isArchived($invoiceId)) {
            $bytes = $this->gateway->downloadInvoicePdf($record->pdfUrl);
            // Clé de stockage = identifiant de la facture (le stockage n'accepte que des UUID).
            $this->archive->put($invoiceId, $bytes);
            $this->invoices->markArchived($invoiceId, $invoiceId, hash('sha256', $bytes), $now);
        }

        return true;
    }

    public function refreshPaymentMethod(?string $customerId): bool
    {
        $artisanId = null === $customerId ? null : $this->invoices->artisanOfCustomer($customerId);
        if (null === $customerId || null === $artisanId) {
            return false;
        }
        $this->invoices->savePaymentMethod($artisanId, $this->gateway->defaultPaymentMethod($customerId), $this->clock->now());

        return true;
    }

    private static function record(StripeEvent $event): ?InvoiceRecord
    {
        $id = $event->string('id');
        $customer = $event->string('customer');
        $status = $event->string('status');
        if (null === $id || null === $customer || !\in_array($status, self::STATUSES, true)) {
            return null;
        }
        $total = $event->int('total') ?? 0;
        $excluding = $event->int('total_excluding_tax') ?? $event->int('subtotal_excluding_tax') ?? $total;
        $date = static fn (?int $ts): ?\DateTimeImmutable => null === $ts ? null : new \DateTimeImmutable('@'.$ts);

        return new InvoiceRecord(
            $id,
            $customer,
            $event->string('parent', 'subscription_details', 'subscription') ?? $event->string('subscription'),
            $event->string('number'),
            $status,
            strtoupper($event->string('currency') ?? 'eur'),
            $excluding,
            $total - $excluding,
            $total,
            $event->int('amount_paid') ?? 0,
            $date($event->int('lines', 'data', '0', 'period', 'start')),
            $date($event->int('lines', 'data', '0', 'period', 'end')),
            $date($event->int('created')) ?? new \DateTimeImmutable(),
            $date($event->int('status_transitions', 'paid_at')),
            $event->string('hosted_invoice_url'),
            $event->string('invoice_pdf'),
        );
    }
}
