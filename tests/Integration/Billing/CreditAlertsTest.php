<?php

declare(strict_types=1);

namespace App\Tests\Integration\Billing;

use App\Billing\Application\Credit\CreditLedger;
use App\Billing\Application\Credit\QuoteCreditAccount;
use App\Billing\Application\EventHandler\NotifyCreditThresholds;
use App\Billing\Domain\Credit\CreditBucket;
use App\Billing\Domain\Credit\Event\CreditsDebited;
use App\Identity\Application\Command\RegisterUser;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** RG-105 / RG-106 — chaque débit publie CreditsDebited ; E-42 aux seuils, E-42b à l'épuisement du gratuit, une seule fois. */
final class CreditAlertsTest extends KernelTestCase
{
    private const string PROFILE = '0190f5e4-0000-7000-8000-0000000c5001';
    private const string USER = '0190f5e4-0000-7000-8000-0000000c5002';
    private const int GRANT = 10;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $bus = self::getContainer()->get(CommandBus::class);
        $ledger = self::getContainer()->get(CreditLedger::class);
        \assert($connection instanceof Connection && $bus instanceof CommandBus && $ledger instanceof CreditLedger);
        $this->connection = $connection;
        $bus->dispatch(new RegisterUser(self::USER, 'dominique.alerte@exemple.test', 'cheval agrafe batterie', 'client', 'Dominique', 'Alerte'));
        $ledger->grantFree('0190f5e4-0000-7000-8000-0000000c5003', self::PROFILE, self::USER, self::GRANT, null, new \DateTimeImmutable());
    }

    public function testDebitPublishesBalanceBeforeAndAfter(): void
    {
        $account = self::getContainer()->get(QuoteCreditAccount::class);
        \assert($account instanceof QuoteCreditAccount);
        $account->debitForQuote('0190f5e4-0000-7000-8000-0000000c5101', self::PROFILE, '0190f5e4-0000-7000-8000-0000000c5201', [CreditBucket::Free], new \DateTimeImmutable());

        $body = DbalValue::string($this->connection->fetchOne('SELECT body FROM shared.outbox_message WHERE message_type = :t', ['t' => CreditsDebited::class]));
        self::assertStringContainsString('"bucketBefore":10', $body);
        self::assertStringContainsString('"bucketAfter":9', $body);
        self::assertStringContainsString(self::USER, $body);
    }

    public function testThresholdAlertsAreSentOnceThenExhaustion(): void
    {
        $handler = self::getContainer()->get(NotifyCreditThresholds::class);
        \assert($handler instanceof NotifyCreditThresholds);
        $debit = static fn (int $before): CreditsDebited => new CreditsDebited(self::PROFILE, self::USER, CreditBucket::Free->value, $before, $before - 1, self::GRANT, $before - 1, new \DateTimeImmutable());

        $handler($debit(4));
        self::assertSame(0, $this->deliveries('E-42'), '70 % : sous le premier seuil');
        $handler($debit(3));
        $handler($debit(3));
        self::assertSame(1, $this->deliveries('E-42'), '80 % : une seule alerte malgré le rejeu');
        self::assertSame('Vous avez utilisé 80 % de vos devis gratuits : il vous en reste 2 sur 10.', DbalValue::string($this->connection->fetchOne("SELECT body FROM notification.in_app WHERE code = 'E-42'")));
        $handler($debit(2));
        self::assertSame(2, $this->deliveries('E-42'), '90 %');
        $handler($debit(1));
        self::assertSame(1, $this->deliveries('E-42b'), 'crédit gratuit épuisé');
        self::assertSame(2, $this->deliveries('E-42'), 'pas d’E-42 à 100 % : E-42b le remplace');
        self::assertSame('Crédit épuisé : souscrivez un forfait pour continuer à envoyer des devis.', DbalValue::string($this->connection->fetchOne("SELECT body FROM notification.in_app WHERE code = 'E-42b'")));

        $handler(new CreditsDebited(self::PROFILE, self::USER, CreditBucket::Plan->value, 1, 0, self::GRANT, 0, new \DateTimeImmutable()));
        self::assertSame(1, $this->deliveries('E-42b'), 'forfait : lot 6');
    }

    private function deliveries(string $code): int
    {
        return DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM notification.delivery WHERE code = :c AND channel = 'email'", ['c' => $code]));
    }
}
