<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Credit;

use App\Billing\Application\Credit\CreditBalance;
use App\Billing\Application\Credit\CreditLedger;
use App\Billing\Domain\Credit\CreditBucket;
use App\Billing\Domain\Credit\LedgerEntryType;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

/**
 * ADR-0006 : écriture du registre et mise à jour du solde dans la même transaction (celle du bus d'événements) ; ancre
 * `credit_account` verrouillée (FOR UPDATE) avant toute écriture, dans un ordre fixe.
 */
final readonly class DbalCreditLedger implements CreditLedger
{
    public function __construct(private Connection $connection)
    {
    }

    public function grantFree(string $entryId, string $artisanId, string $userId, int $amount, ?\DateTimeImmutable $expiresAt, \DateTimeImmutable $at): bool
    {
        $this->connection->executeStatement(
            'INSERT INTO billing.credit_account (artisan_id, user_id, created_at) VALUES (:artisan, :user, :at) ON CONFLICT (artisan_id) DO NOTHING',
            ['artisan' => $artisanId, 'user' => $userId, 'at' => $at->format(\DATE_ATOM)],
        );
        $this->connection->executeStatement('SELECT 1 FROM billing.credit_account WHERE artisan_id = :artisan FOR UPDATE', ['artisan' => $artisanId]);

        $inserted = $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO billing.credit_ledger_entry (id, artisan_id, type, bucket, amount, expires_at, reference_type, reference_id, created_at)
                VALUES (:id, :artisan, :type, :bucket, :amount, :expires, 'artisan_profile', :artisan, :at)
                ON CONFLICT (artisan_id) WHERE type = 'free_grant' DO NOTHING
                SQL,
            [
                'id' => $entryId, 'artisan' => $artisanId, 'type' => LedgerEntryType::FreeGrant->value, 'bucket' => CreditBucket::Free->value,
                'amount' => $amount, 'expires' => $expiresAt?->format(\DATE_ATOM), 'at' => $at->format(\DATE_ATOM),
            ],
        );
        if (0 === $inserted) {
            return false;
        }
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO billing.credit_balance (artisan_id, free, plan, extra, free_granted, updated_at) VALUES (:artisan, :amount, 0, 0, :amount, :at)
                ON CONFLICT (artisan_id) DO UPDATE SET free = billing.credit_balance.free + EXCLUDED.free, free_granted = billing.credit_balance.free_granted + EXCLUDED.free_granted, updated_at = EXCLUDED.updated_at
                SQL,
            ['artisan' => $artisanId, 'amount' => $amount, 'at' => $at->format(\DATE_ATOM)],
        );

        return true;
    }

    public function balanceOfUser(string $userId): ?CreditBalance
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $userId)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(
            'SELECT b.free, b.plan, b.extra, b.free_granted FROM billing.credit_account a JOIN billing.credit_balance b ON b.artisan_id = a.artisan_id WHERE a.user_id = :user',
            ['user' => $userId],
        );

        return false === $row ? null : new CreditBalance(DbalValue::int($row['free']), DbalValue::int($row['plan']), DbalValue::int($row['extra']), DbalValue::int($row['free_granted']));
    }
}
