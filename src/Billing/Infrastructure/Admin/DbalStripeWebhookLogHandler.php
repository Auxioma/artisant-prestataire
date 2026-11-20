<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Admin;

use App\Shared\Application\Admin\StripeWebhookLog;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

/** BO15.1 — journal des webhooks Stripe reçus (sans le contenu des événements). */
final readonly class DbalStripeWebhookLogHandler implements QueryHandler
{
    public const array STATUSES = ['received', 'processed', 'ignored', 'failed'];

    public function __construct(private Connection $connection)
    {
    }

    /** @return array{total: int, rows: list<array{event_id: string, type: string, livemode: bool, status: string, attempts: int, last_error: ?string, received_at: \DateTimeImmutable, processed_at: ?\DateTimeImmutable}>} */
    public function __invoke(StripeWebhookLog $query): array
    {
        $status = \in_array($query->status, self::STATUSES, true) ? $query->status : null;
        $where = 'CAST(:status AS text) IS NULL OR status = :status';
        $total = DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM billing.processed_stripe_event WHERE '.$where, ['status' => $status]));
        $rows = $this->connection->fetchAllAssociative(
            'SELECT event_id, type, livemode, status, attempts, last_error, received_at, processed_at FROM billing.processed_stripe_event
              WHERE '.$where.' ORDER BY received_at DESC, event_id LIMIT :limit OFFSET :offset',
            ['status' => $status, 'limit' => max(1, $query->limit), 'offset' => max(0, $query->offset)],
        );

        return ['total' => $total, 'rows' => array_map(static fn (array $r): array => [
            'event_id' => DbalValue::string($r['event_id']),
            'type' => DbalValue::string($r['type']),
            'livemode' => true === $r['livemode'],
            'status' => DbalValue::string($r['status']),
            'attempts' => DbalValue::int($r['attempts']),
            'last_error' => DbalValue::nullableString($r['last_error']),
            'received_at' => new \DateTimeImmutable(DbalValue::string($r['received_at'])),
            'processed_at' => null === $r['processed_at'] ? null : new \DateTimeImmutable(DbalValue::string($r['processed_at'])),
        ], $rows)];
    }
}
