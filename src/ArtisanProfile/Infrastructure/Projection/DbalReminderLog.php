<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Infrastructure\Projection;

use App\ArtisanProfile\Application\Port\ReminderLog;
use Doctrine\DBAL\Connection;

final readonly class DbalReminderLog implements ReminderLog
{
    public function __construct(private Connection $connection)
    {
    }

    public function markSent(string $kind, string $subjectId, string $step, \DateTimeImmutable $at): bool
    {
        return 1 === (int) $this->connection->executeStatement(
            'INSERT INTO artisan.reminder_sent (kind, subject_id, step, sent_at) VALUES (:kind, :subject, :step, :at) ON CONFLICT DO NOTHING',
            ['kind' => $kind, 'subject' => $subjectId, 'step' => $step, 'at' => $at->format(\DATE_ATOM)],
        );
    }
}
