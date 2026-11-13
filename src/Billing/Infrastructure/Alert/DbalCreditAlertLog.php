<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Alert;

use App\Billing\Application\Alert\CreditAlertLog;
use Doctrine\DBAL\Connection;

final readonly class DbalCreditAlertLog implements CreditAlertLog
{
    public function __construct(private Connection $connection)
    {
    }

    public function record(string $artisanId, string $kind, string $period, int $threshold, \DateTimeImmutable $at): bool
    {
        return 1 === (int) $this->connection->executeStatement(
            'INSERT INTO billing.credit_alert (artisan_id, kind, period, threshold, sent_at) VALUES (:a, :k, :p, :t, :at) ON CONFLICT DO NOTHING',
            ['a' => $artisanId, 'k' => $kind, 'p' => $period, 't' => $threshold, 'at' => $at->format(\DATE_ATOM)],
        );
    }
}
