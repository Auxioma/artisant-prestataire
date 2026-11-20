<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Persistence;

use App\Notification\Application\Admin\DeliveryLogPage;
use App\Notification\Application\Admin\DeliveryLogQuery;
use App\Notification\Application\Admin\DeliveryLogReader;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/** BO11.2 — l'adresse recherchée n'est jamais stockée : seule son empreinte est comparée. */
final readonly class DbalDeliveryLogReader implements DeliveryLogReader
{
    public function __construct(private Connection $connection)
    {
    }

    public function page(DeliveryLogQuery $query): DeliveryLogPage
    {
        $where = ['TRUE'];
        $params = [];
        if (null !== $query->code && '' !== $query->code) {
            $where[] = 'code = :code';
            $params['code'] = $query->code;
        }
        if (null !== $query->status && \array_key_exists($query->status, DeliveryLogPage::STATUSES)) {
            $where[] = 'status = :status';
            $params['status'] = $query->status;
        }
        if (null !== $query->channel && \in_array($query->channel, ['email', 'in_app', 'sms'], true)) {
            $where[] = 'channel = :channel';
            $params['channel'] = $query->channel;
        }
        if (null !== $query->recipient && '' !== trim($query->recipient)) {
            $where[] = 'recipient_hash = :hash';
            $params['hash'] = hash('sha256', mb_strtolower(trim($query->recipient)));
        }
        $page = max(1, $query->page);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT *, count(*) OVER () AS total FROM notification.delivery WHERE '.implode(' AND ', $where).' ORDER BY created_at DESC, id DESC LIMIT :l OFFSET :o',
            $params + ['l' => DeliveryLogQuery::PER_PAGE, 'o' => ($page - 1) * DeliveryLogQuery::PER_PAGE],
            ['l' => ParameterType::INTEGER, 'o' => ParameterType::INTEGER],
        );

        return new DeliveryLogPage(
            array_map(static fn (array $r): array => [
                'id' => DbalValue::string($r['id']),
                'code' => DbalValue::string($r['code']),
                'version' => DbalValue::int($r['template_version']),
                'channel' => DbalValue::string($r['channel']),
                'recipient' => DbalValue::nullableString($r['recipient_masked']),
                'status' => DbalValue::string($r['status']),
                'error' => DbalValue::nullableString($r['error']),
                'at' => new \DateTimeImmutable(DbalValue::string($r['created_at'])),
                'updatedAt' => new \DateTimeImmutable(DbalValue::string($r['updated_at'])),
            ], $rows),
            [] === $rows ? 0 : DbalValue::int($rows[0]['total']),
            $page,
            DeliveryLogQuery::PER_PAGE,
        );
    }
}
