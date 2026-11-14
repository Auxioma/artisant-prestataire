<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Invoice;

use App\Billing\Application\Invoice\InvoiceRecord;
use App\Billing\Application\Invoice\Invoices;
use App\Billing\Application\Invoice\InvoiceView;
use App\Billing\Application\Port\PaymentMethodSummary;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class DbalInvoices implements Invoices
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(private Connection $connection)
    {
    }

    public function artisanOfCustomer(string $customerId): ?string
    {
        $id = $this->connection->fetchOne('SELECT artisan_id FROM billing.credit_account WHERE stripe_customer_id = :c', ['c' => $customerId]);

        return false === $id ? null : DbalValue::string($id);
    }

    public function customerOfArtisan(string $artisanId): ?string
    {
        $id = $this->connection->fetchOne('SELECT stripe_customer_id FROM billing.credit_account WHERE artisan_id = :a', ['a' => $artisanId]);

        return \is_string($id) ? $id : null;
    }

    public function upsert(string $newId, string $artisanId, InvoiceRecord $record, \DateTimeImmutable $at): string
    {
        $date = static fn (?\DateTimeImmutable $d): ?string => $d?->format(\DATE_ATOM);

        return DbalValue::string($this->connection->fetchOne(
            <<<'SQL'
                INSERT INTO billing.invoice (id, artisan_id, stripe_invoice_id, stripe_subscription_id, number, status, currency, amount_ht_cents, vat_cents, amount_ttc_cents, amount_paid_cents, period_start, period_end, issued_at, paid_at, hosted_url, updated_at)
                VALUES (:id, :artisan, :stripe, :sub, :number, :status, :currency, :ht, :vat, :ttc, :paid, :start, :end, :issued, :paid_at, :hosted, :at)
                ON CONFLICT (stripe_invoice_id) DO UPDATE SET number = coalesce(excluded.number, billing.invoice.number), status = excluded.status,
                    amount_ht_cents = excluded.amount_ht_cents, vat_cents = excluded.vat_cents, amount_ttc_cents = excluded.amount_ttc_cents,
                    amount_paid_cents = excluded.amount_paid_cents, paid_at = coalesce(excluded.paid_at, billing.invoice.paid_at),
                    hosted_url = coalesce(excluded.hosted_url, billing.invoice.hosted_url), updated_at = excluded.updated_at
                RETURNING id
                SQL,
            [
                'id' => $newId, 'artisan' => $artisanId, 'stripe' => $record->stripeInvoiceId, 'sub' => $record->stripeSubscriptionId, 'number' => $record->number,
                'status' => $record->status, 'currency' => $record->currency, 'ht' => $record->amountHtCents, 'vat' => $record->vatCents, 'ttc' => $record->amountTtcCents,
                'paid' => $record->amountPaidCents, 'start' => $date($record->periodStart), 'end' => $date($record->periodEnd), 'issued' => $date($record->issuedAt),
                'paid_at' => $date($record->paidAt), 'hosted' => $record->hostedUrl, 'at' => $date($at),
            ],
        ));
    }

    public function isArchived(string $invoiceId): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM billing.invoice WHERE id = :id AND pdf_key IS NOT NULL', ['id' => $invoiceId]);
    }

    public function markArchived(string $invoiceId, string $pdfKey, string $sha256, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement('UPDATE billing.invoice SET pdf_key = :k, pdf_sha256 = :h, archived_at = :at WHERE id = :id AND pdf_key IS NULL', ['k' => $pdfKey, 'h' => $sha256, 'at' => $at->format(\DATE_ATOM), 'id' => $invoiceId]);
    }

    public function ofArtisan(string $artisanId, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT * FROM billing.invoice WHERE artisan_id = :a AND status <> 'draft' ORDER BY issued_at DESC LIMIT :limit",
            ['a' => $artisanId, 'limit' => max(1, $limit)],
            ['limit' => ParameterType::INTEGER],
        );

        return array_map(self::view(...), $rows);
    }

    public function find(string $artisanId, string $invoiceId): ?array
    {
        if (1 !== preg_match(self::UUID, $invoiceId)) {
            return null;
        }
        $row = $this->connection->fetchAssociative('SELECT * FROM billing.invoice WHERE id = :id AND artisan_id = :a', ['id' => $invoiceId, 'a' => $artisanId]);

        return false === $row ? null : ['view' => self::view($row), 'pdf_key' => DbalValue::nullableString($row['pdf_key']), 'pdf_sha256' => DbalValue::nullableString($row['pdf_sha256'])];
    }

    public function savePaymentMethod(string $artisanId, ?PaymentMethodSummary $method, \DateTimeImmutable $at): void
    {
        if (null === $method) {
            $this->connection->executeStatement('DELETE FROM billing.payment_method WHERE artisan_id = :a', ['a' => $artisanId]);

            return;
        }
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO billing.payment_method (artisan_id, type, brand, last4, exp_month, exp_year, updated_at) VALUES (:a, :type, :brand, :last4, :m, :y, :at)
                ON CONFLICT (artisan_id) DO UPDATE SET type = excluded.type, brand = excluded.brand, last4 = excluded.last4, exp_month = excluded.exp_month, exp_year = excluded.exp_year, updated_at = excluded.updated_at
                SQL,
            ['a' => $artisanId, 'type' => \in_array($method->type, ['card', 'sepa_debit'], true) ? $method->type : 'other', 'brand' => $method->brand, 'last4' => $method->last4, 'm' => $method->expMonth, 'y' => $method->expYear, 'at' => $at->format(\DATE_ATOM)],
        );
    }

    public function paymentMethod(string $artisanId): ?PaymentMethodSummary
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM billing.payment_method WHERE artisan_id = :a', ['a' => $artisanId]);
        $int = static fn (mixed $v): ?int => null === $v ? null : DbalValue::int($v);

        return false === $row ? null : new PaymentMethodSummary(DbalValue::string($row['type']), DbalValue::nullableString($row['brand']), DbalValue::nullableString($row['last4']), $int($row['exp_month']), $int($row['exp_year']));
    }

    /** @param array<string, mixed> $row */
    private static function view(array $row): InvoiceView
    {
        $date = static fn (mixed $v): ?\DateTimeImmutable => null === $v ? null : new \DateTimeImmutable(DbalValue::string($v));

        return new InvoiceView(
            DbalValue::string($row['id']),
            DbalValue::nullableString($row['number']),
            DbalValue::string($row['status']),
            DbalValue::int($row['amount_ht_cents']),
            DbalValue::int($row['vat_cents']),
            DbalValue::int($row['amount_ttc_cents']),
            new \DateTimeImmutable(DbalValue::string($row['issued_at'])),
            $date($row['period_start']),
            $date($row['period_end']),
            null !== $row['pdf_key'],
            DbalValue::nullableString($row['hosted_url']),
        );
    }
}
