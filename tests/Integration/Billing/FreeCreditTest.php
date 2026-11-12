<?php

declare(strict_types=1);

namespace App\Tests\Integration\Billing;

use App\Billing\Application\Credit\CreditGaugeOf;
use App\Billing\Application\Credit\GrantFreeCredit;
use App\Billing\Domain\Credit\CreditGauge;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Domain\Event\Integration\ProfessionalAccountVerified;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** RG-044 / RG-107 / ADR-0006 : crédit gratuit unique à la vérification, registre en ajout seul, solde dénormalisé. */
final class FreeCreditTest extends KernelTestCase
{
    private const string PROFILE = '0190f5e4-0000-7000-8000-0000000c0001';
    private const string USER = '0190f5e4-0000-7000-8000-0000000c0002';

    private Connection $connection;
    private GrantFreeCredit $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $handler = self::getContainer()->get(GrantFreeCredit::class);
        \assert($connection instanceof Connection && $handler instanceof GrantFreeCredit);
        [$this->connection, $this->handler] = [$connection, $handler];
        $this->connection->executeStatement("DELETE FROM admin.setting WHERE key LIKE 'credits.%'");
        $this->clearSettingsCache();
    }

    private function clearSettingsCache(): void
    {
        $cache = self::getContainer()->get('cache.settings');
        \assert($cache instanceof \Psr\Cache\CacheItemPoolInterface);
        $cache->clear();
    }

    private function setting(string $key, string $value, string $type): void
    {
        $this->connection->executeStatement('INSERT INTO admin.setting (key, value, type, updated_at) VALUES (:k, :v, :t, now()) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value', ['k' => $key, 'v' => $value, 't' => $type]);
        $this->clearSettingsCache();
    }

    private function value(string $sql): string
    {
        $value = $this->connection->fetchOne($sql);

        return \is_int($value) || \is_bool($value) ? var_export($value, true) : DbalValue::string($value);
    }

    public function testFreeCreditIsGrantedOnceAtVerification(): void
    {
        ($this->handler)(new ProfessionalAccountVerified(self::PROFILE, self::USER, new \DateTimeImmutable('2026-11-13 10:00')));
        ($this->handler)(new ProfessionalAccountVerified(self::PROFILE, self::USER, new \DateTimeImmutable('2026-12-01 10:00')));

        self::assertSame('1|100|free|', $this->value("SELECT count(*) || '|' || sum(amount) || '|' || min(bucket) || '|' || coalesce(min(expires_at)::text, '') FROM billing.credit_ledger_entry"), 'une seule écriture, sans expiration par défaut');
        self::assertSame('100|100', $this->value("SELECT free || '|' || free_granted FROM billing.credit_balance"));
        self::assertSame(1, (int) $this->value("SELECT count(*) FROM admin.audit_log WHERE action = 'billing.free_credit_granted'"));

        $queries = self::getContainer()->get(QueryBus::class);
        \assert($queries instanceof QueryBus);
        $gauge = $queries->ask(new CreditGaugeOf(self::USER));
        self::assertInstanceOf(CreditGauge::class, $gauge);
        self::assertSame([100, CreditGauge::NORMAL], [$gauge->remaining, $gauge->state]);
    }

    public function testSettingsDriveAmountAndValidity(): void
    {
        $this->setting('credits.free_on_signup', '25', 'int');
        $this->setting('credits.free_validity_days', '90', 'nullable_int');

        ($this->handler)(new ProfessionalAccountVerified(self::PROFILE, self::USER, new \DateTimeImmutable('2026-11-13 10:00:00+00:00')));

        self::assertSame('25|2027-02-11', $this->value("SELECT amount || '|' || (expires_at AT TIME ZONE 'UTC')::date FROM billing.credit_ledger_entry"));
    }

    public function testNoGrantWhenTheFreeCreditIsDisabled(): void
    {
        $this->setting('credits.free_on_signup', '0', 'int');

        ($this->handler)(new ProfessionalAccountVerified(self::PROFILE, self::USER, new \DateTimeImmutable()));

        self::assertSame('0', $this->value('SELECT count(*) FROM billing.credit_ledger_entry'));
    }

    public function testLedgerIsAppendOnly(): void
    {
        ($this->handler)(new ProfessionalAccountVerified(self::PROFILE, self::USER, new \DateTimeImmutable()));

        foreach (['UPDATE billing.credit_ledger_entry SET amount = 1000', 'DELETE FROM billing.credit_ledger_entry'] as $sql) {
            $this->connection->beginTransaction();
            try {
                $this->connection->executeStatement($sql);
                self::fail('Registre modifiable : '.$sql);
            } catch (\Doctrine\DBAL\Exception) {
            } finally {
                $this->connection->rollBack();
            }
        }
        self::assertSame('100', $this->value('SELECT sum(amount) FROM billing.credit_ledger_entry'));
    }
}
