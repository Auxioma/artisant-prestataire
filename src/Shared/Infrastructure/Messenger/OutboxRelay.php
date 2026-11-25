<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Relaie les messages de l'outbox vers event.bus. `FOR UPDATE SKIP LOCKED` autorise plusieurs relais en parallèle.
 */
final readonly class OutboxRelay
{
    public function __construct(
        private Connection $connection,
        private SerializerInterface $messengerSerializer,
        private MessageBusInterface $eventBus,
    ) {
    }

    /**
     * @return int nombre de messages relayés
     */
    public function relayBatch(int $limit): int
    {
        return $this->connection->transactional(function (Connection $connection) use ($limit): int {
            $rows = $connection->fetchAllAssociative(
                'SELECT id, body, headers FROM shared.outbox_message WHERE dispatched_at IS NULL ORDER BY occurred_at, id LIMIT :limit FOR UPDATE SKIP LOCKED',
                ['limit' => $limit],
            );
            foreach ($rows as $row) {
                $envelope = $this->messengerSerializer->decode([
                    'body' => DbalValue::string($row['body']),
                    'headers' => self::headers(DbalValue::json($row['headers'])),
                ]);
                $this->eventBus->dispatch($envelope->getMessage());
                $connection->executeStatement('UPDATE shared.outbox_message SET dispatched_at = now() WHERE id = :id', ['id' => $row['id']]);
            }

            return \count($rows);
        });
    }

    /**
     * @return array<string, string>
     */
    private static function headers(mixed $decoded): array
    {
        if (!\is_array($decoded)) {
            throw new \UnexpectedValueException('En-têtes d\'outbox invalides.');
        }
        $headers = [];
        foreach ($decoded as $name => $value) {
            if (!\is_string($name) || !\is_string($value)) {
                throw new \UnexpectedValueException('En-têtes d\'outbox invalides.');
            }
            $headers[$name] = $value;
        }

        return $headers;
    }
}
