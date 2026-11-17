<?php

declare(strict_types=1);

namespace App\QuoteRequest\Infrastructure\Persistence;

use App\QuoteRequest\Application\Port\RequestAddenda;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalRequestAddenda implements RequestAddenda
{
    public function __construct(private Connection $connection)
    {
    }

    public function add(string $id, string $requestId, string $body, \DateTimeImmutable $at): void
    {
        $this->connection->insert('request.request_addendum', ['id' => $id, 'request_id' => $requestId, 'body' => $body, 'created_at' => $at->format(\DATE_ATOM)]);
    }

    public function count(string $requestId): int
    {
        return DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM request.request_addendum WHERE request_id = :r', ['r' => $requestId]));
    }

    public function of(string $requestId): array
    {
        return array_map(
            static fn (array $row): array => ['body' => DbalValue::string($row['body']), 'createdAt' => new \DateTimeImmutable(DbalValue::string($row['created_at']))],
            $this->connection->fetchAllAssociative('SELECT body, created_at FROM request.request_addendum WHERE request_id = :r ORDER BY created_at, id', ['r' => $requestId]),
        );
    }
}
