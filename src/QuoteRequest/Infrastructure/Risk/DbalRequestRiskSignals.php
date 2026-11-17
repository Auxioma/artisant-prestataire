<?php

declare(strict_types=1);

namespace App\QuoteRequest\Infrastructure\Risk;

use App\QuoteRequest\Application\Port\RequestRiskSignals;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalRequestRiskSignals implements RequestRiskSignals
{
    /** Texte comparé sans casse, ponctuation ni espaces (même règle côté PHP et PostgreSQL). */
    private const string NORMALIZE_SQL = "regexp_replace(lower(description), '[^[:alnum:]]+', '', 'g')";

    public function __construct(private Connection $connection)
    {
    }

    public function countByClientSince(string $clientId, \DateTimeImmutable $since): int
    {
        return DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM request.quote_request WHERE client_id = :c AND submitted_at >= :since', ['c' => $clientId, 'since' => $since->format(\DATE_ATOM)]));
    }

    public function countByIpSince(string $ipHash, \DateTimeImmutable $since): int
    {
        return DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM request.quote_request WHERE ip_hash = :ip AND submitted_at >= :since', ['ip' => $ipHash, 'since' => $since->format(\DATE_ATOM)]));
    }

    public function hasSameDescriptionSince(string $description, \DateTimeImmutable $since): bool
    {
        $normalized = (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($description));

        return '' !== $normalized && false !== $this->connection->fetchOne(
            'SELECT 1 FROM request.quote_request WHERE submitted_at >= :since AND '.self::NORMALIZE_SQL.' = :n LIMIT 1',
            ['since' => $since->format(\DATE_ATOM), 'n' => $normalized],
        );
    }
}
