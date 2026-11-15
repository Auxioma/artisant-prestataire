<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Privacy;

use App\Shared\Application\Privacy\PersonalDataSource;
use App\Shared\Infrastructure\Persistence\PersonalDataRows;
use Doctrine\DBAL\Connection;

/** Preuves de consentement : documents légaux acceptés et choix de cookies. */
final readonly class ConsentPersonalData implements PersonalDataSource
{
    public function __construct(private Connection $connection)
    {
    }

    public function key(): string
    {
        return 'consentements';
    }

    public function title(): string
    {
        return 'Documents acceptés et consentements';
    }

    public function collect(string $userId): array
    {
        return PersonalDataRows::format($this->connection->fetchAllAssociative(<<<'SQL'
            SELECT 'document' AS nature, d.type AS objet, d.title AS detail, a.accepted_at AS date
            FROM identity.legal_acceptance a JOIN identity.legal_document d ON d.id = a.legal_document_id WHERE a.user_id = :u
            UNION ALL
            SELECT 'cookies', c.source, c.choices::text, c.given_at FROM identity.consent c WHERE c.user_id = :u
            ORDER BY date
            SQL, ['u' => $userId]));
    }
}
