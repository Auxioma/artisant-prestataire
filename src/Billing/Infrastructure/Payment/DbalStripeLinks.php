<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Payment;

use App\Billing\Application\Payment\StripeCustomerAccounts;
use App\Billing\Application\Payment\StripePriceLinks;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

/** Identifiants Stripe conservés en base : prix des forfaits (`plan_price`) et client de chaque artisan (`credit_account`). */
final readonly class DbalStripeLinks implements StripePriceLinks, StripeCustomerAccounts
{
    public function __construct(private Connection $connection)
    {
    }

    public function linkPrice(string $priceId, string $stripePriceId): void
    {
        $this->connection->executeStatement('UPDATE billing.plan_price SET stripe_price_id = :s WHERE id = :id AND stripe_price_id IS NULL', ['s' => $stripePriceId, 'id' => $priceId]);
    }

    public function retired(string $planId): array
    {
        $rows = $this->connection->fetchAllKeyValue(
            'SELECT id, stripe_price_id FROM billing.plan_price WHERE plan_id = :p AND NOT active AND stripe_price_id IS NOT NULL AND stripe_retired_at IS NULL ORDER BY created_at',
            ['p' => $planId],
        );

        $retired = [];
        foreach ($rows as $priceId => $stripeId) {
            $retired[DbalValue::string($priceId)] = DbalValue::string($stripeId);
        }

        return $retired;
    }

    public function markRetired(string $priceId, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement('UPDATE billing.plan_price SET stripe_retired_at = :at WHERE id = :id', ['at' => $at->format(\DATE_ATOM), 'id' => $priceId]);
    }

    public function customerId(string $artisanId): ?string
    {
        $id = $this->connection->fetchOne('SELECT stripe_customer_id FROM billing.credit_account WHERE artisan_id = :a', ['a' => $artisanId]);

        return \is_string($id) ? $id : null;
    }

    public function linkCustomer(string $artisanId, string $userId, string $customerId, \DateTimeImmutable $at): string
    {
        return DbalValue::string($this->connection->fetchOne(
            <<<'SQL'
                INSERT INTO billing.credit_account (artisan_id, user_id, stripe_customer_id, created_at) VALUES (:a, :u, :c, :at)
                ON CONFLICT (artisan_id) DO UPDATE SET stripe_customer_id = coalesce(billing.credit_account.stripe_customer_id, excluded.stripe_customer_id)
                RETURNING stripe_customer_id
                SQL,
            ['a' => $artisanId, 'u' => $userId, 'c' => $customerId, 'at' => $at->format(\DATE_ATOM)],
        ));
    }
}
