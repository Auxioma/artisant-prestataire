<?php

declare(strict_types=1);

namespace App\QuoteRequest\Infrastructure\Retention;

use App\QuoteRequest\Application\Retention\RequestArchive;
use App\QuoteRequest\Domain\Request\RequestStatus;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class DbalRequestArchive implements RequestArchive
{
    /** Statuts clos (aucune activité attendue). */
    private const array CLOSED = ['completed', 'expired', 'cancelled', 'rejected', 'awarded'];

    public function __construct(private Connection $connection)
    {
    }

    public function archivableBefore(\DateTimeImmutable $before, int $limit): array
    {
        return array_map(DbalValue::string(...), $this->connection->fetchFirstColumn(
            'SELECT id FROM request.quote_request WHERE archived_at IS NULL AND status IN (:closed) AND updated_at < :b ORDER BY updated_at LIMIT :l',
            ['closed' => self::CLOSED, 'b' => $before->format(\DATE_ATOM), 'l' => $limit],
            ['closed' => ArrayParameterType::STRING, 'l' => ParameterType::INTEGER],
        ));
    }

    public function inactiveOfClient(string $clientId): array
    {
        return array_map(DbalValue::string(...), $this->connection->fetchFirstColumn(
            'SELECT id FROM request.quote_request WHERE client_id = :c AND archived_at IS NULL AND status NOT IN (:active)',
            ['c' => $clientId, 'active' => array_map(static fn (RequestStatus $s): string => $s->value, RequestStatus::ACTIVE)],
            ['active' => ArrayParameterType::STRING],
        ));
    }

    public function archive(string $requestId, \DateTimeImmutable $now): array
    {
        return $this->connection->transactional(static function (Connection $c) use ($requestId, $now): array {
            $files = array_map(DbalValue::string(...), $c->fetchFirstColumn('DELETE FROM request.request_attachment WHERE request_id = :r RETURNING file_id', ['r' => $requestId]));
            $c->executeStatement('DELETE FROM request.request_addendum WHERE request_id = :r', ['r' => $requestId]);
            $c->executeStatement('DELETE FROM request.request_address WHERE request_id = :r', ['r' => $requestId]);
            $c->executeStatement(
                "UPDATE request.quote_request SET description = :p, answers = '{}'::jsonb, preferred_slots = '[]'::jsonb, summary = NULL, ip_hash = NULL, archived_at = :at WHERE id = :r",
                ['p' => self::PLACEHOLDER, 'at' => $now->format(\DATE_ATOM), 'r' => $requestId],
            );

            return $files;
        });
    }
}
