<?php

declare(strict_types=1);

namespace App\Review\Infrastructure\Admin;

use App\Shared\Application\Admin\AdminQueueSource;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

/** BO01.2 — avis et réponses d'artisans en attente de modération (onglet « Avis » de BO05). */
final readonly class ReviewQueues implements AdminQueueSource
{
    public function __construct(private Connection $connection)
    {
    }

    public function queues(): array
    {
        return ['reviews_pending' => DbalValue::int($this->connection->fetchOne(
            "SELECT count(*) FROM review.review WHERE status = 'pending' OR reply_status = 'pending'",
        ))];
    }
}
