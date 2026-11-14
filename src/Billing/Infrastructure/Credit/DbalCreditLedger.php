<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Credit;

use App\Billing\Application\Credit\CreditBalance;
use App\Billing\Application\Credit\CreditLedger;
use App\Billing\Application\Credit\InsufficientCredits;
use App\Billing\Application\Credit\QuoteCreditAccount;
use App\Billing\Domain\Credit\CreditBucket;
use App\Billing\Domain\Credit\Event\CreditsDebited;
use App\Billing\Domain\Credit\LedgerEntryType;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

/**
 * ADR-0006 : écriture du registre et mise à jour du solde dans la même transaction (celle du bus d'événements) ; ancre
 * `credit_account` verrouillée (FOR UPDATE) avant toute écriture, dans un ordre fixe. Chaque débit publie
 * `CreditsDebited` dans la même transaction (outbox) : alertes de seuil RG-105.
 */
final readonly class DbalCreditLedger implements CreditLedger, QuoteCreditAccount
{
    public function __construct(private Connection $connection, private EventBus $events)
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

    public function grantPeriod(string $entryId, string $artisanId, string $periodId, int $amount, ?\DateTimeImmutable $expiresAt, ?string $expirePreviousPeriodId, string $expiryEntryId, \DateTimeImmutable $at): bool
    {
        $balance = $this->lockForQuote($artisanId);
        if (null === $balance) {
            $this->connection->executeStatement('INSERT INTO billing.credit_balance (artisan_id, free, plan, extra, free_granted, updated_at) VALUES (:a, 0, 0, 0, 0, :at) ON CONFLICT (artisan_id) DO NOTHING', ['a' => $artisanId, 'at' => $at->format(\DATE_ATOM)]);
            $balance = $this->lockForQuote($artisanId) ?? throw new \LogicException('Compte crédits introuvable.');
        }
        $reference = static fn (string $id, string $type, int $value, string $period): array => ['id' => $id, 'artisan' => $artisanId, 'type' => $type, 'amount' => $value, 'period' => $period, 'at' => $at->format(\DATE_ATOM)];
        $insert = <<<'SQL'
            INSERT INTO billing.credit_ledger_entry (id, artisan_id, type, bucket, amount, expires_at, period_id, reference_type, reference_id, created_at)
            VALUES (:id, :artisan, :type, 'plan', :amount, :expires, :period, 'subscription_period', :period, :at)
            ON CONFLICT (type, reference_type, reference_id) WHERE type IN ('quote_debit', 'refund', 'period_grant', 'expiry') DO NOTHING
            SQL;
        if (null !== $expirePreviousPeriodId && $balance->plan > 0) {
            $expired = (int) $this->connection->executeStatement($insert, $reference($expiryEntryId, LedgerEntryType::Expiry->value, -$balance->plan, $expirePreviousPeriodId) + ['expires' => null]);
            if (1 === $expired) {
                $this->connection->executeStatement('UPDATE billing.credit_balance SET plan = 0, updated_at = :at WHERE artisan_id = :a', ['a' => $artisanId, 'at' => $at->format(\DATE_ATOM)]);
            }
        }
        if ($amount <= 0) {
            return false;
        }
        $granted = (int) $this->connection->executeStatement($insert, $reference($entryId, LedgerEntryType::PeriodGrant->value, $amount, $periodId) + ['expires' => $expiresAt?->format(\DATE_ATOM)]);
        if (1 !== $granted) {
            return false;
        }
        $this->connection->executeStatement('UPDATE billing.credit_balance SET plan = plan + :n, updated_at = :at WHERE artisan_id = :a', ['n' => $amount, 'a' => $artisanId, 'at' => $at->format(\DATE_ATOM)]);

        return true;
    }

    public function grantPlanChange(string $entryId, string $artisanId, string $changeId, string $periodId, int $amount, \DateTimeImmutable $at): bool
    {
        if ($amount <= 0 || null === $this->lockForQuote($artisanId)) {
            return false;
        }
        $granted = (int) $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO billing.credit_ledger_entry (id, artisan_id, type, bucket, amount, period_id, reference_type, reference_id, created_at)
                VALUES (:id, :artisan, 'period_grant', 'plan', :amount, :period, 'plan_change', :change, :at)
                ON CONFLICT (type, reference_type, reference_id) WHERE type IN ('quote_debit', 'refund', 'period_grant', 'expiry') DO NOTHING
                SQL,
            ['id' => $entryId, 'artisan' => $artisanId, 'amount' => $amount, 'period' => $periodId, 'change' => $changeId, 'at' => $at->format(\DATE_ATOM)],
        );
        if (1 !== $granted) {
            return false;
        }
        $this->connection->executeStatement('UPDATE billing.credit_balance SET plan = plan + :n, updated_at = :at WHERE artisan_id = :a', ['n' => $amount, 'a' => $artisanId, 'at' => $at->format(\DATE_ATOM)]);

        return true;
    }

    public function sendingSuspended(string $artisanId): bool
    {
        return false !== $this->connection->fetchOne("SELECT 1 FROM billing.subscription WHERE artisan_id = :a AND status = 'suspended'", ['a' => $artisanId]);
    }

    public function lockForQuote(string $artisanId): ?CreditBalance
    {
        $row = $this->connection->fetchAssociative(
            'SELECT b.free, b.plan, b.extra, b.free_granted FROM billing.credit_account a JOIN billing.credit_balance b ON b.artisan_id = a.artisan_id WHERE a.artisan_id = :artisan FOR UPDATE OF a',
            ['artisan' => $artisanId],
        );

        return false === $row ? null : new CreditBalance(DbalValue::int($row['free']), DbalValue::int($row['plan']), DbalValue::int($row['extra']), DbalValue::int($row['free_granted']));
    }

    public function debitForQuote(string $entryId, string $artisanId, string $quoteId, array $order, \DateTimeImmutable $at): CreditBucket
    {
        $balance = $this->lockForQuote($artisanId) ?? throw new InsufficientCredits('Aucun crédit disponible.');
        foreach ($order as $bucket) {
            $available = match ($bucket) {
                CreditBucket::Free => $balance->free,
                CreditBucket::Plan => $balance->plan,
                CreditBucket::Extra => $balance->extra,
            };
            if ($available <= 0) {
                continue;
            }
            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO billing.credit_ledger_entry (id, artisan_id, type, bucket, amount, reference_type, reference_id, created_at)
                    VALUES (:id, :artisan, :type, :bucket, -1, 'quote', :quote, :at)
                    SQL,
                ['id' => $entryId, 'artisan' => $artisanId, 'type' => LedgerEntryType::QuoteDebit->value, 'bucket' => $bucket->value, 'quote' => $quoteId, 'at' => $at->format(\DATE_ATOM)],
            );
            $this->connection->executeStatement(
                \sprintf('UPDATE billing.credit_balance SET %1$s = %1$s - 1, updated_at = :at WHERE artisan_id = :artisan', $bucket->value),
                ['artisan' => $artisanId, 'at' => $at->format(\DATE_ATOM)],
            );
            $userId = DbalValue::string($this->connection->fetchOne('SELECT user_id FROM billing.credit_account WHERE artisan_id = :artisan', ['artisan' => $artisanId]));
            $this->events->publish(new CreditsDebited($artisanId, $userId, $bucket->value, $available, $available - 1, $balance->freeGranted, $balance->total() - 1, $at));

            return $bucket;
        }

        throw new InsufficientCredits('Aucun crédit disponible.');
    }

    public function refundQuote(string $entryId, string $artisanId, string $quoteId, \DateTimeImmutable $at): bool
    {
        $this->lockForQuote($artisanId);
        $bucket = $this->connection->fetchOne(
            "SELECT bucket FROM billing.credit_ledger_entry WHERE type = 'quote_debit' AND reference_type = 'quote' AND reference_id = :q AND artisan_id = :a",
            ['q' => $quoteId, 'a' => $artisanId],
        );
        $bucket = false === $bucket ? null : CreditBucket::tryFrom(DbalValue::string($bucket));
        if (null === $bucket) {
            return false;
        }
        $inserted = (int) $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO billing.credit_ledger_entry (id, artisan_id, type, bucket, amount, reference_type, reference_id, created_at)
                VALUES (:id, :artisan, :type, :bucket, 1, 'quote', :quote, :at)
                ON CONFLICT (type, reference_type, reference_id) WHERE type IN ('quote_debit', 'refund', 'period_grant', 'expiry') DO NOTHING
                SQL,
            ['id' => $entryId, 'artisan' => $artisanId, 'type' => LedgerEntryType::Refund->value, 'bucket' => $bucket->value, 'quote' => $quoteId, 'at' => $at->format(\DATE_ATOM)],
        );
        if (1 !== $inserted) {
            return false;
        }
        $this->connection->executeStatement(
            \sprintf('UPDATE billing.credit_balance SET %1$s = %1$s + 1, updated_at = :at WHERE artisan_id = :artisan', $bucket->value),
            ['artisan' => $artisanId, 'at' => $at->format(\DATE_ATOM)],
        );

        return true;
    }
}
