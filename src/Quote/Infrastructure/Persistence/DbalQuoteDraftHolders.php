<?php

declare(strict_types=1);

namespace App\Quote\Infrastructure\Persistence;

use App\Quote\Application\Port\QuoteDraftHolders;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalQuoteDraftHolders implements QuoteDraftHolders
{
    public function __construct(private Connection $connection)
    {
    }

    public function userIdsWithDraftOn(string $requestId): array
    {
        return array_map(DbalValue::string(...), $this->connection->fetchFirstColumn(
            "SELECT p.user_id FROM quote.quote q JOIN artisan.artisan_profile p ON p.id = q.artisan_id WHERE q.request_id = :r AND q.status = 'draft'",
            ['r' => $requestId],
        ));
    }
}
