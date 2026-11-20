<?php

declare(strict_types=1);

namespace App\QuoteRequest\Infrastructure\Admin;

use App\Shared\Application\Admin\AdminQueueSource;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

/** BO01.2 — demandes en modération. */
final readonly class RequestQueues implements AdminQueueSource
{
    public function __construct(private Connection $connection)
    {
    }

    public function queues(): array
    {
        return ['requests_moderation' => DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM request.quote_request WHERE status = 'pending_moderation'"))];
    }
}
