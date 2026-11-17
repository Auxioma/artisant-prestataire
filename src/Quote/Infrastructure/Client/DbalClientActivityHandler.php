<?php

declare(strict_types=1);

namespace App\Quote\Infrastructure\Client;

use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Application\Client\ClientActivity;
use App\Shared\Application\Client\ClientActivityCounts;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

/** Demandes déposées, devis reçus (envoyés), rendez-vous confirmés ou réalisés, devis acceptés. */
final readonly class DbalClientActivityHandler implements QueryHandler
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(ClientActivity $query): ClientActivityCounts
    {
        if (1 !== preg_match(self::UUID, $query->userId)) {
            return new ClientActivityCounts(0, 0, 0, 0);
        }
        $row = $this->connection->fetchAssociative(<<<'SQL'
            SELECT
                (SELECT count(*) FROM request.quote_request r WHERE r.client_id = :u) AS requests,
                (SELECT count(*) FROM quote.quote q JOIN request.quote_request r ON r.id = q.request_id WHERE r.client_id = :u AND q.status <> 'draft') AS quotes,
                (SELECT count(*) FROM quote.quote q JOIN request.quote_request r ON r.id = q.request_id WHERE r.client_id = :u AND q.status = 'accepted') AS accepted,
                (SELECT count(*) FROM quote.appointment a JOIN quote.quote q ON q.id = a.quote_id JOIN request.quote_request r ON r.id = q.request_id WHERE r.client_id = :u AND a.status IN ('confirmed', 'completed')) AS appointments
            SQL, ['u' => $query->userId]) ?: [];

        return new ClientActivityCounts(DbalValue::int($row['requests'] ?? 0), DbalValue::int($row['quotes'] ?? 0), DbalValue::int($row['appointments'] ?? 0), DbalValue::int($row['accepted'] ?? 0));
    }
}
