<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Webhook;

use App\Billing\Application\Webhook\ProcessedEvents;
use App\Billing\Application\Webhook\StripeEvent;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalProcessedEvents implements ProcessedEvents
{
    public function __construct(private Connection $connection)
    {
    }

    public function record(StripeEvent $event, string $payloadSha256, \DateTimeImmutable $at): bool
    {
        return 1 === (int) $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO billing.processed_stripe_event (event_id, type, livemode, payload, payload_sha256, received_at)
                VALUES (:id, :type, :live, :payload, :hash, :at) ON CONFLICT (event_id) DO NOTHING
                SQL,
            ['id' => $event->id, 'type' => $event->type, 'live' => $event->livemode ? 'true' : 'false', 'payload' => json_encode($event->object, \JSON_THROW_ON_ERROR), 'hash' => $payloadSha256, 'at' => $at->format(\DATE_ATOM)],
        );
    }

    public function find(string $eventId): ?StripeEvent
    {
        $row = $this->connection->fetchAssociative('SELECT type, livemode, payload FROM billing.processed_stripe_event WHERE event_id = :id', ['id' => $eventId]);
        if (false === $row) {
            return null;
        }
        $object = DbalValue::json($row['payload']);

        /** @var array<string, mixed> $object */
        $object = \is_array($object) ? $object : [];

        return new StripeEvent($eventId, DbalValue::string($row['type']), true === $row['livemode'], $object);
    }

    public function isDone(string $eventId): bool
    {
        return false !== $this->connection->fetchOne("SELECT 1 FROM billing.processed_stripe_event WHERE event_id = :id AND status IN ('processed', 'ignored')", ['id' => $eventId]);
    }

    public function markProcessed(string $eventId, bool $ignored, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(
            'UPDATE billing.processed_stripe_event SET status = :s, attempts = attempts + 1, processed_at = :at WHERE event_id = :id',
            ['s' => $ignored ? 'ignored' : 'processed', 'at' => $at->format(\DATE_ATOM), 'id' => $eventId],
        );
    }
}
