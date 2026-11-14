<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Subscription;

use App\Billing\Domain\Plan\BillingInterval;
use App\Billing\Domain\Subscription\Subscription;
use App\Billing\Domain\Subscription\SubscriptionId;
use App\Billing\Domain\Subscription\SubscriptionRepository;
use App\Billing\Domain\Subscription\SubscriptionStatus;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalSubscriptionRepository implements SubscriptionRepository
{
    private const string LIVE = "('active', 'past_due', 'suspended')";

    public function __construct(private Connection $connection)
    {
    }

    public function find(SubscriptionId $id): ?Subscription
    {
        return $this->one('SELECT * FROM billing.subscription WHERE id = :v', $id->value);
    }

    public function findByStripeId(string $stripeSubscriptionId): ?Subscription
    {
        return $this->one('SELECT * FROM billing.subscription WHERE stripe_subscription_id = :v', $stripeSubscriptionId);
    }

    public function liveOf(string $artisanId): ?Subscription
    {
        return $this->one('SELECT * FROM billing.subscription WHERE artisan_id = :v AND status IN '.self::LIVE, $artisanId);
    }

    public function save(Subscription $subscription): void
    {
        $row = [
            'plan_id' => $subscription->planId(),
            'plan_price_id' => $subscription->planPriceId(),
            'interval' => $subscription->interval()->value,
            'status' => $subscription->status()->value,
            'stripe_subscription_id' => $subscription->stripeSubscriptionId(),
            'current_period_start' => $subscription->periodStart()?->format(\DATE_ATOM),
            'current_period_end' => $subscription->periodEnd()?->format(\DATE_ATOM),
            'cancel_at_period_end' => $subscription->cancelAtPeriodEnd() ? 'true' : 'false',
            'updated_at' => $subscription->updatedAt()->format(\DATE_ATOM),
        ];
        $updated = (int) $this->connection->executeStatement(
            'UPDATE billing.subscription SET '.implode(', ', array_map(static fn (string $c): string => $c.' = :'.$c, array_keys($row))).', version = version + 1 WHERE id = :id',
            $row + ['id' => $subscription->id()->value],
        );
        if (0 === $updated) {
            $this->connection->insert('billing.subscription', $row + [
                'id' => $subscription->id()->value,
                'artisan_id' => $subscription->artisanId(),
                'created_at' => $subscription->createdAt()->format(\DATE_ATOM),
            ]);
        }
    }

    private function one(string $sql, string $value): ?Subscription
    {
        $row = $this->connection->fetchAssociative($sql.' ORDER BY created_at DESC LIMIT 1', ['v' => $value]);
        if (false === $row) {
            return null;
        }
        $date = static fn (mixed $v): ?\DateTimeImmutable => null === $v ? null : new \DateTimeImmutable(DbalValue::string($v));

        return Subscription::restore(
            new SubscriptionId(DbalValue::string($row['id'])),
            DbalValue::string($row['artisan_id']),
            DbalValue::string($row['plan_id']),
            DbalValue::string($row['plan_price_id']),
            BillingInterval::from(DbalValue::string($row['interval'])),
            SubscriptionStatus::from(DbalValue::string($row['status'])),
            DbalValue::nullableString($row['stripe_subscription_id']),
            $date($row['current_period_start']),
            $date($row['current_period_end']),
            true === $row['cancel_at_period_end'],
            new \DateTimeImmutable(DbalValue::string($row['created_at'])),
            new \DateTimeImmutable(DbalValue::string($row['updated_at'])),
        );
    }
}
