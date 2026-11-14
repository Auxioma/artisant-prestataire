<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Subscription;

use App\Billing\Application\Subscription\SubscriptionPeriods;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalSubscriptionPeriods implements SubscriptionPeriods
{
    public function __construct(private Connection $connection)
    {
    }

    public function record(string $periodId, string $subscriptionId, string $stripeInvoiceId, \DateTimeImmutable $start, \DateTimeImmutable $end, int $credits, int $amountPaidCents, \DateTimeImmutable $at): bool
    {
        return 1 === (int) $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO billing.subscription_period (id, subscription_id, stripe_invoice_id, period_start, period_end, credits, amount_paid_cents, created_at)
                VALUES (:id, :sub, :invoice, :start, :end, :credits, :paid, :at) ON CONFLICT (stripe_invoice_id) DO NOTHING
                SQL,
            ['id' => $periodId, 'sub' => $subscriptionId, 'invoice' => $stripeInvoiceId, 'start' => $start->format(\DATE_ATOM), 'end' => $end->format(\DATE_ATOM), 'credits' => $credits, 'paid' => $amountPaidCents, 'at' => $at->format(\DATE_ATOM)],
        );
    }

    public function current(string $subscriptionId): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT id, credits FROM billing.subscription_period WHERE subscription_id = :s ORDER BY period_start DESC LIMIT 1', ['s' => $subscriptionId]);

        return false === $row ? null : ['id' => DbalValue::string($row['id']), 'credits' => DbalValue::int($row['credits'])];
    }

    public function previousOf(string $subscriptionId, \DateTimeImmutable $start): ?string
    {
        $id = $this->connection->fetchOne('SELECT id FROM billing.subscription_period WHERE subscription_id = :s AND period_start < :start ORDER BY period_start DESC LIMIT 1', ['s' => $subscriptionId, 'start' => $start->format(\DATE_ATOM)]);

        return false === $id ? null : DbalValue::string($id);
    }
}
