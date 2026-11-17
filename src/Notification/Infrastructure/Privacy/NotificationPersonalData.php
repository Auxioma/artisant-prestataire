<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Privacy;

use App\Shared\Application\Privacy\PersonalDataSource;
use App\Shared\Infrastructure\Persistence\PersonalDataRows;
use Doctrine\DBAL\Connection;

/** Préférences d'envoi et notifications reçues sur le site (90 derniers jours). */
final readonly class NotificationPersonalData implements PersonalDataSource
{
    public function __construct(private Connection $connection)
    {
    }

    public function key(): string
    {
        return 'notifications';
    }

    public function title(): string
    {
        return 'Notifications et préférences';
    }

    public function collect(string $userId): array
    {
        return PersonalDataRows::format($this->connection->fetchAllAssociative(<<<'SQL'
            SELECT 'preference' AS nature, topic || ' / ' || channel AS objet, CASE WHEN enabled THEN 'envoyé' ELSE 'coupé' END AS detail, updated_at AS date
            FROM notification.preference WHERE user_id = :u
            UNION ALL
            SELECT 'notification', code, body, created_at FROM notification.in_app WHERE user_id = :u
            ORDER BY date
            SQL, ['u' => $userId]));
    }
}
