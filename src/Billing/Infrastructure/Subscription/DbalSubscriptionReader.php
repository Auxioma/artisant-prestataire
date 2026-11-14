<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Subscription;

use App\Billing\Application\Subscription\LedgerLine;
use App\Billing\Application\Subscription\SubscriptionReader;
use App\Billing\Domain\Credit\LedgerEntryType;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalSubscriptionReader implements SubscriptionReader
{
    public function __construct(private Connection $connection)
    {
    }

    public function artisanOfUser(string $userId): ?string
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $userId)) {
            return null;
        }
        $id = $this->connection->fetchOne('SELECT artisan_id FROM billing.credit_account WHERE user_id = :u', ['u' => $userId]);

        return false === $id ? null : DbalValue::string($id);
    }

    public function priceCents(string $planPriceId): ?int
    {
        $cents = $this->connection->fetchOne('SELECT amount_cents FROM billing.plan_price WHERE id = :id', ['id' => $planPriceId]);

        return false === $cents ? null : DbalValue::int($cents);
    }

    public function userOfArtisan(string $artisanId): ?string
    {
        $id = $this->connection->fetchOne('SELECT user_id FROM billing.credit_account WHERE artisan_id = :a', ['a' => $artisanId]);

        return false === $id ? null : DbalValue::string($id);
    }

    public function planNameOfPrice(string $planPriceId): ?string
    {
        $row = $this->connection->fetchAssociative('SELECT p.name, pp.interval FROM billing.plan_price pp JOIN billing.plan p ON p.id = pp.plan_id WHERE pp.id = :id', ['id' => $planPriceId]);

        return false === $row ? null : DbalValue::string($row['name']).('year' === $row['interval'] ? ' (annuel)' : ' (mensuel)');
    }

    public function priceByStripeId(string $stripePriceId): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT plan_id, id, interval FROM billing.plan_price WHERE stripe_price_id = :s', ['s' => $stripePriceId]);

        return false === $row ? null : ['plan_id' => DbalValue::string($row['plan_id']), 'price_id' => DbalValue::string($row['id']), 'interval' => DbalValue::string($row['interval'])];
    }

    public function history(string $artisanId, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT * FROM (
                    SELECT e.created_at, e.type, e.amount, e.reference_type, e.reason,
                           coalesce(q.number, e.reference_id::text) AS reference,
                           sum(e.amount) OVER (ORDER BY e.created_at, e.id) AS balance_after, e.id
                    FROM billing.credit_ledger_entry e
                    LEFT JOIN quote.quote q ON e.reference_type = 'quote' AND q.id = e.reference_id
                    WHERE e.artisan_id = :a
                ) h ORDER BY created_at DESC, id DESC LIMIT :limit
                SQL,
            ['a' => $artisanId, 'limit' => max(1, $limit)],
            ['limit' => \Doctrine\DBAL\ParameterType::INTEGER],
        );

        return array_map(static fn (array $r): LedgerLine => new LedgerLine(
            new \DateTimeImmutable(DbalValue::string($r['created_at'])),
            LedgerEntryType::from(DbalValue::string($r['type'])),
            DbalValue::int($r['amount']),
            DbalValue::int($r['balance_after']),
            DbalValue::nullableString($r['reference_type']),
            DbalValue::nullableString($r['reference']),
            DbalValue::nullableString($r['reason']),
        ), $rows);
    }
}
