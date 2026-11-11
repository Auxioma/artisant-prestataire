<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use App\Shared\Application\Bus\EventBus;
use App\Shared\Domain\Event\DomainEvent;
use App\Shared\Domain\Identifier\IdGenerator;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Publication transactionnelle (ADR-0011) : les événements sont écrits dans shared.outbox_message avec la même
 * connexion que la transaction métier ; OutboxRelay les transmet ensuite à event.bus (at-least-once).
 */
final readonly class OutboxEventBus implements EventBus
{
    public function __construct(
        private Connection $connection,
        private SerializerInterface $messengerSerializer,
        private IdGenerator $ids,
    ) {
    }

    public function publish(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            $encoded = $this->messengerSerializer->encode(new Envelope($event));
            $this->connection->insert('shared.outbox_message', [
                'id' => $this->ids->next(),
                'message_type' => $event::class,
                'body' => $encoded['body'],
                'headers' => json_encode($encoded['headers'] ?? [], \JSON_THROW_ON_ERROR),
                'occurred_at' => $event->occurredAt()->format('Y-m-d H:i:s.uP'),
            ]);
        }
    }
}
