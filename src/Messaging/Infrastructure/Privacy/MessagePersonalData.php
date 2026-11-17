<?php

declare(strict_types=1);

namespace App\Messaging\Infrastructure\Privacy;

use App\Shared\Application\Privacy\PersonalDataSource;
use App\Shared\Infrastructure\Persistence\PersonalDataRows;
use Doctrine\DBAL\Connection;

/** Messages écrits par le titulaire (les messages des autres participants relèvent de leurs propres données). */
final readonly class MessagePersonalData implements PersonalDataSource
{
    public function __construct(private Connection $connection)
    {
    }

    public function key(): string
    {
        return 'messages';
    }

    public function title(): string
    {
        return 'Messages envoyés';
    }

    public function collect(string $userId): array
    {
        return PersonalDataRows::format($this->connection->fetchAllAssociative(<<<'SQL'
            SELECT m.conversation_id AS conversation, m.created_at AS envoye_at, m.body AS texte,
                   (SELECT string_agg(a.original_name, ', ') FROM messaging.attachment a WHERE a.message_id = m.id) AS pieces_jointes
            FROM messaging.message m WHERE m.sender_user_id = :u ORDER BY m.created_at
            SQL, ['u' => $userId]));
    }
}
