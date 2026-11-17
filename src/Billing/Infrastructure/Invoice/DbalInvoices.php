<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Invoice;

use App\Billing\Application\Invoice\CreditNoteRecord;
use App\Billing\Application\Invoice\InvoiceAdminRow;
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
    private const string SELECT = 'SELECT i.*, (SELECT coalesce(sum(n.refunded_cents), 0) FROM billing.credit_note n WHERE n.invoice_id = i.id) AS refunded_cents FROM billing.invoice i';

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
            self::SELECT." WHERE i.artisan_id = :a AND i.status <> 'draft' ORDER BY i.issued_at DESC LIMIT :limit",
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
        $row = $this->connection->fetchAssociative(self::SELECT.' WHERE i.id = :id AND i.artisan_id = :a', ['id' => $invoiceId, 'a' => $artisanId]);

        return false === $row ? null : ['view' => self::view($row), 'pdf_key' => DbalValue::nullableString($row['pdf_key']), 'pdf_sha256' => DbalValue::nullableString($row['pdf_sha256'])];
    }

    public function findAny(string $invoiceId): ?InvoiceAdminRow
    {
        if (1 !== preg_match(self::UUID, $invoiceId)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(self::SELECT.' WHERE i.id = :id', ['id' => $invoiceId]);

        return false === $row ? null : new InvoiceAdminRow(DbalValue::string($row['artisan_id']), DbalValue::string($row['stripe_invoice_id']), self::view($row));
    }

    public function invoiceIdByStripeId(string $stripeInvoiceId): ?string
    {
        $id = $this->connection->fetchOne('SELECT id FROM billing.invoice WHERE stripe_invoice_id = :s', ['s' => $stripeInvoiceId]);

        return false === $id ? null : DbalValue::string($id);
    }

    public function recordCreditNote(string $id, string $invoiceId, CreditNoteRecord $note, \DateTimeImmutable $at): bool
    {
        return 1 === (int) $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO billing.credit_note (id, invoice_id, artisan_id, stripe_credit_note_id, number, amount_cents, refunded_cents, reason, comment, admin_id, pdf_url, created_at)
                VALUES (:id, :invoice, :artisan, :stripe, :number, :amount, :refunded, :reason, :comment, :admin, :pdf, :at) ON CONFLICT (stripe_credit_note_id) DO NOTHING
                SQL,
            ['id' => $id, 'invoice' => $invoiceId, 'artisan' => $note->artisanId, 'stripe' => $note->stripeCreditNoteId, 'number' => $note->number, 'amount' => $note->amountCents, 'refunded' => $note->refundedCents, 'reason' => $note->reason, 'comment' => $note->comment, 'admin' => $note->adminId, 'pdf' => $note->pdfUrl, 'at' => $at->format(\DATE_ATOM)],
        );
    }

    public function markCreditNoteNotified(string $stripeCreditNoteId, \DateTimeImmutable $at): bool
    {
        return 1 === (int) $this->connection->executeStatement('UPDATE billing.credit_note SET notified_at = :at WHERE stripe_credit_note_id = :s AND notified_at IS NULL', ['s' => $stripeCreditNoteId, 'at' => $at->format(\DATE_ATOM)]);
    }

    public function creditNotesOf(string $artisanId): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT n.*, i.number AS invoice_number FROM billing.credit_note n JOIN billing.invoice i ON i.id = n.invoice_id WHERE n.artisan_id = :a ORDER BY n.created_at DESC', ['a' => $artisanId]);

        return array_map(static fn (array $r): CreditNoteRecord => new CreditNoteRecord(
            DbalValue::string($r['stripe_credit_note_id']),
            DbalValue::string($r['artisan_id']),
            DbalValue::nullableString($r['number']),
            DbalValue::int($r['amount_cents']),
            DbalValue::int($r['refunded_cents']),
            DbalValue::nullableString($r['reason']),
            DbalValue::nullableString($r['comment']),
            DbalValue::nullableString($r['admin_id']),
            DbalValue::nullableString($r['pdf_url']),
            DbalValue::nullableString($r['invoice_number']),
            new \DateTimeImmutable(DbalValue::string($r['created_at'])),
        ), $rows);
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
                ON CONFLICT (artisan_id) DO UPDATE SET type = excluded.type, brand = excluded.brand, last4 = excluded.last4, exp_month = excluded.exp_month, exp_year = excluded.exp_year, updated_at = excluded.updated_at,
                    expiry_notified_at = CASE WHEN (billing.payment_method.last4, billing.payment_method.exp_month, billing.payment_method.exp_year) IS NOT DISTINCT FROM (excluded.last4, excluded.exp_month, excluded.exp_year) THEN billing.payment_method.expiry_notified_at END
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

    public function cardsExpiringBefore(\DateTimeImmutable $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT pm.artisan_id, a.user_id, pm.brand, pm.last4, pm.exp_month, pm.exp_year
                FROM billing.payment_method pm
                JOIN billing.credit_account a ON a.artisan_id = pm.artisan_id
                JOIN billing.subscription s ON s.artisan_id = pm.artisan_id AND s.status IN ('active', 'past_due') AND NOT s.cancel_at_period_end
                WHERE pm.type = 'card' AND pm.expiry_notified_at IS NULL AND pm.exp_year IS NOT NULL AND pm.exp_month IS NOT NULL
                  AND make_date(pm.exp_year, pm.exp_month, 1) + interval '1 month' <= :limit
                SQL,
            ['limit' => $limit->format(\DATE_ATOM)],
        );

        return array_map(static fn (array $r): array => [
            'artisan_id' => DbalValue::string($r['artisan_id']),
            'user_id' => DbalValue::string($r['user_id']),
            'brand' => DbalValue::nullableString($r['brand']),
            'last4' => DbalValue::string($r['last4']),
            'exp_month' => DbalValue::int($r['exp_month']),
            'exp_year' => DbalValue::int($r['exp_year']),
        ], $rows);
    }

    public function markExpiryNotified(string $artisanId, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement('UPDATE billing.payment_method SET expiry_notified_at = :at WHERE artisan_id = :a', ['a' => $artisanId, 'at' => $at->format(\DATE_ATOM)]);
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
            DbalValue::int($row['amount_paid_cents']),
            \array_key_exists('refunded_cents', $row) ? DbalValue::int($row['refunded_cents']) : 0,
        );
    }
}
