<?php

declare(strict_types=1);

namespace App\Tests\Integration\Administration;

use App\Administration\Application\Command\ChangeSetting;
use App\Administration\Infrastructure\Settings\DbalSettingsProvider;
use App\Administration\Infrastructure\Settings\DbalSettingStore;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Settings\InvalidSettingValue;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** ADR-0007 / RG-014 / BO13 : paramètres persistés, historisés, audités, publiés en outbox. */
final class SettingsPersistenceTest extends KernelTestCase
{
    private const string ADMIN = '0190f5d2-6c1e-7a3b-9c4d-000000000001';

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        // Indépendant d'un éventuel app:settings:sync sur la base de test (annulé par la transaction DAMA).
        $this->connection->executeStatement('DELETE FROM admin.setting');
    }

    public function testChangeSettingThroughCommandBusWritesValueHistoryAuditAndOutbox(): void
    {
        $bus = self::getContainer()->get(CommandBus::class);
        \assert($bus instanceof CommandBus);

        $bus->dispatch(new ChangeSetting('quotes.max_per_request', '4', self::ADMIN, 'Pilote Normandie', '203.0.113.7'));

        self::assertSame('4', $this->connection->fetchOne("SELECT value::text FROM admin.setting WHERE key = 'quotes.max_per_request'"));
        $history = $this->connection->fetchAssociative("SELECT value_before::text AS before, value_after::text AS after, reason FROM admin.setting_history WHERE key = 'quotes.max_per_request'");
        self::assertSame(['before' => null, 'after' => '4', 'reason' => 'Pilote Normandie'], $history);
        self::assertSame(1, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM admin.audit_log WHERE action = 'administration.setting_changed' AND subject_id = 'quotes.max_per_request'")));
        self::assertSame(1, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM shared.outbox_message WHERE message_type LIKE '%SettingChanged' AND dispatched_at IS NULL")));
    }

    public function testInvalidValueRollsBackEverything(): void
    {
        $bus = self::getContainer()->get(CommandBus::class);
        \assert($bus instanceof CommandBus);

        try {
            $bus->dispatch(new ChangeSetting('auth.password_min_length', 8, self::ADMIN, 'Baisse refusée'));
            self::fail('Exception attendue');
        } catch (InvalidSettingValue) {
        }

        self::assertSame(0, DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM admin.setting_history')));
        self::assertSame(0, DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM admin.audit_log')));
    }

    public function testProviderFallsBackToDefaultAndIgnoresCorruptedStoredValue(): void
    {
        $provider = self::getContainer()->get(SettingsProvider::class);
        \assert($provider instanceof DbalSettingsProvider);

        self::assertSame(100, $provider->int(SettingKey::CreditsFreeOnSignup));

        // Valeur stockée hors bornes (ex. bornes durcies après coup) : ignorée au profit du défaut.
        $this->connection->executeStatement("INSERT INTO admin.setting (key, value, type, updated_at) VALUES ('quotes.max_per_request', '999', 'int', now())");
        $provider->reset();
        self::getContainer()->get('cache.settings')->clear();

        self::assertSame(3, $provider->int(SettingKey::QuotesMaxPerRequest));
    }

    public function testStoreReturnsFalseWhenValueIsUnchanged(): void
    {
        $store = self::getContainer()->get(DbalSettingStore::class);
        \assert($store instanceof DbalSettingStore);
        $now = new \DateTimeImmutable();

        self::assertTrue($store->save(SettingKey::BillingGraceDays, 10, self::ADMIN, 'Essai', $now));
        self::assertFalse($store->save(SettingKey::BillingGraceDays, 10, self::ADMIN, 'Essai', $now));
        self::assertSame(10, $store->find(SettingKey::BillingGraceDays));
    }

    public function testHistoryIsAppendOnly(): void
    {
        $this->connection->executeStatement("INSERT INTO admin.setting (key, value, type, updated_at) VALUES ('plans.rollover', 'false', 'bool', now())");
        $this->connection->executeStatement("INSERT INTO admin.setting_history (key, value_after, reason, changed_at) VALUES ('plans.rollover', 'false', 'Init', now())");

        $this->expectExceptionMessageMatches('/ajout seul/');
        $this->connection->executeStatement("UPDATE admin.setting_history SET reason = 'falsifié'");
    }
}
