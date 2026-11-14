<?php

declare(strict_types=1);

namespace App\Billing\Application\Invoice;

use App\Billing\Application\Port\PaymentMethodSummary;

/** Factures répliquées (`billing.invoice`) et moyen de paiement par défaut (`billing.payment_method`). */
interface Invoices
{
    public function artisanOfCustomer(string $customerId): ?string;

    public function customerOfArtisan(string $artisanId): ?string;

    /** Crée ou met à jour la réplique ; renvoie son identifiant local. */
    public function upsert(string $newId, string $artisanId, InvoiceRecord $record, \DateTimeImmutable $at): string;

    public function isArchived(string $invoiceId): bool;

    public function markArchived(string $invoiceId, string $pdfKey, string $sha256, \DateTimeImmutable $at): void;

    /** @return list<InvoiceView> plus récentes d'abord */
    public function ofArtisan(string $artisanId, int $limit): array;

    /** @return array{view: InvoiceView, pdf_key: ?string, pdf_sha256: ?string}|null */
    public function find(string $artisanId, string $invoiceId): ?array;

    public function savePaymentMethod(string $artisanId, ?PaymentMethodSummary $method, \DateTimeImmutable $at): void;

    public function paymentMethod(string $artisanId): ?PaymentMethodSummary;
}
