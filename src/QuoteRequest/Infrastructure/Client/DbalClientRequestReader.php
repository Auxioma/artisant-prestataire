<?php

declare(strict_types=1);

namespace App\QuoteRequest\Infrastructure\Client;

use App\QuoteRequest\Application\Client\ClientRequestRow;
use App\QuoteRequest\Application\Client\ClientRequests;
use App\QuoteRequest\Application\Port\ClientRequestReader;
use App\QuoteRequest\Domain\Request\ProjectType;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class DbalClientRequestReader implements ClientRequestReader
{
    private const array GROUP_STATUSES = [
        'en-cours' => ['pending_moderation', 'open', 'full', 'awarded'],
        'terminees' => ['completed'],
        'closes' => ['expired', 'cancelled', 'rejected'],
    ];
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const int SEARCH_MAX = 80;
    private const string PHOTO = "(SELECT a.file_id FROM request.request_attachment a JOIN shared.stored_file f ON f.id = a.file_id AND f.status = 'available' WHERE a.request_id = r.id ORDER BY a.position LIMIT 1)";

    public function __construct(private Connection $connection)
    {
    }

    public function page(ClientRequests $query, \DateTimeImmutable $now): array
    {
        $qb = $this->connection->createQueryBuilder()->from('request.quote_request', 'r')
            ->join('r', 'catalog.job', 'j', 'j.id = r.job_id')
            ->leftJoin('r', 'catalog.commune', 'c', 'c.insee = r.city_insee')
            ->where('r.client_id = :client')->andWhere("r.status <> 'draft'")
            ->setParameter('client', $query->clientId);
        if (isset(self::GROUP_STATUSES[$query->group ?? ''])) {
            $qb->andWhere('r.status IN (:statuses)')->setParameter('statuses', self::GROUP_STATUSES[$query->group], ArrayParameterType::STRING);
        }
        $search = trim(mb_substr($query->search, 0, self::SEARCH_MAX));
        if ('' !== $search) {
            $qb->andWhere('(j.name ILIKE :q OR r.title ILIKE :q OR c.name ILIKE :q OR r.number ILIKE :q)')->setParameter('q', '%'.addcslashes($search, '%_\\').'%');
        }
        if (null !== $query->sinceDays && $query->sinceDays > 0) {
            $qb->andWhere('r.submitted_at >= :since')->setParameter('since', $now->modify(\sprintf('-%d days', $query->sinceDays))->format(\DATE_ATOM));
        }
        $total = DbalValue::int((clone $qb)->select('count(*)')->executeQuery()->fetchOne());
        $rows = $qb->select('r.id', 'r.number', 'r.title', 'r.project_type', 'j.name AS job_name', 'coalesce(c.name, r.postal_code) AS city_name', 'r.postal_code', 'r.submitted_at', 'r.status', 'r.received_quotes_count', 'r.max_quotes', self::PHOTO.' AS photo_id', 'r.relaunch_count', 'r.expires_at')
            ->orderBy('r.submitted_at', 'DESC')->addOrderBy('r.id')
            ->setFirstResult((max(1, $query->page) - 1) * $query->perPage)->setMaxResults($query->perPage)
            ->executeQuery()->fetchAllAssociative();

        return ['items' => array_map(self::hydrate(...), $rows), 'total' => $total];
    }

    public function stats(string $clientId): array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT count(*) FILTER (WHERE status <> 'draft') AS total,
                    count(*) FILTER (WHERE status = 'pending_moderation' OR (status = 'open' AND received_quotes_count = 0)) AS waiting,
                    count(*) FILTER (WHERE status IN ('open', 'full', 'awarded') AND received_quotes_count > 0) AS quoted,
                    count(*) FILTER (WHERE status = 'completed') AS completed,
                    count(*) FILTER (WHERE status IN ('pending_moderation', 'open', 'full', 'awarded')) AS active
             FROM request.quote_request WHERE client_id = :client",
            ['client' => $clientId],
        ) ?: [];

        return ['total' => DbalValue::int($row['total'] ?? 0), 'waiting' => DbalValue::int($row['waiting'] ?? 0), 'quoted' => DbalValue::int($row['quoted'] ?? 0), 'completed' => DbalValue::int($row['completed'] ?? 0), 'active' => DbalValue::int($row['active'] ?? 0)];
    }

    public function photos(string $clientId, string $requestId): array
    {
        if (1 !== preg_match(self::UUID, $requestId)) {
            return [];
        }

        return array_map(DbalValue::string(...), $this->connection->fetchFirstColumn(
            "SELECT a.file_id FROM request.request_attachment a JOIN request.quote_request r ON r.id = a.request_id AND r.client_id = :client
             JOIN shared.stored_file f ON f.id = a.file_id AND f.status = 'available' WHERE a.request_id = :r ORDER BY a.position",
            ['client' => $clientId, 'r' => $requestId],
        ));
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): ClientRequestRow
    {
        $jobName = DbalValue::string($row['job_name']);
        $title = trim(DbalValue::nullableString($row['title']) ?? '');
        $expires = DbalValue::nullableString($row['expires_at']);

        return new ClientRequestRow(
            DbalValue::string($row['id']),
            DbalValue::string($row['number']),
            '' !== $title ? $title : (ProjectType::tryFrom(DbalValue::string($row['project_type']))?->label() ?? 'Projet').' · '.$jobName,
            $jobName,
            DbalValue::string($row['city_name']).' ('.DbalValue::string($row['postal_code']).')',
            new \DateTimeImmutable(DbalValue::string($row['submitted_at'])),
            DbalValue::string($row['status']),
            DbalValue::int($row['received_quotes_count']),
            DbalValue::int($row['max_quotes']),
            DbalValue::nullableString($row['photo_id']),
            DbalValue::int($row['relaunch_count']),
            null === $expires ? null : new \DateTimeImmutable($expires),
        );
    }
}
