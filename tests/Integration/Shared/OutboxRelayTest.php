<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Administration\Domain\Event\SettingChanged;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Infrastructure\Messenger\OutboxRelay;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/** ADR-0011 : les événements écrits en outbox sont relayés une seule fois vers le transport asynchrone. */
final class OutboxRelayTest extends KernelTestCase
{
    public function testEventsAreRelayedOnceToAsyncTransportWithJsonPayload(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $eventBus = $container->get(EventBus::class);
        $relay = $container->get(OutboxRelay::class);
        $connection = $container->get(Connection::class);
        $transport = $container->get('messenger.transport.async');
        \assert($eventBus instanceof EventBus && $relay instanceof OutboxRelay && $connection instanceof Connection && $transport instanceof InMemoryTransport);

        $eventBus->publish(
            new SettingChanged('quotes.max_per_request', null, new \DateTimeImmutable('2026-11-11T10:00:00+00:00')),
            new SettingChanged('billing.grace_days', null, new \DateTimeImmutable('2026-11-11T10:00:01+00:00')),
        );

        $body = DbalValue::string($connection->fetchOne('SELECT body FROM shared.outbox_message ORDER BY occurred_at LIMIT 1'));
        self::assertJson($body, 'Sérialisation JSON (pas de serialize() PHP)');

        self::assertSame(2, $relay->relayBatch(10));
        self::assertSame(0, $relay->relayBatch(10));

        $sent = $transport->getSent();
        self::assertCount(2, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(SettingChanged::class, $message);
        self::assertSame('quotes.max_per_request', $message->key);
        self::assertSame(0, DbalValue::int($connection->fetchOne('SELECT count(*) FROM shared.outbox_message WHERE dispatched_at IS NULL')));
    }
}
