<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Admin;

use App\Shared\Application\Admin\AdminQueueSource;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

/** BO01.2 — paiements échoués : abonnements en retard de paiement (délai de grâce en cours). */
final readonly class BillingQueues implements AdminQueueSource
{
    public function __construct(private Connection $connection)
    {
    }

    public function queues(): array
    {
        return ['payments_failed' => DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM billing.subscription WHERE status = 'past_due'"))];
    }
}
