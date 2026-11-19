<?php

declare(strict_types=1);

namespace App\Moderation\Infrastructure\Admin;

use App\Shared\Application\Admin\AdminQueueSource;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

/** BO01.2 — signalements ouverts (onglet « Signalements » de BO05). */
final readonly class ReportQueues implements AdminQueueSource
{
    public function __construct(private Connection $connection)
    {
    }

    public function queues(): array
    {
        return ['reports_open' => DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM moderation.report WHERE status = 'open'"))];
    }
}
