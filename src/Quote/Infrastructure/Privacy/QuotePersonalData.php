<?php

declare(strict_types=1);

namespace App\Quote\Infrastructure\Privacy;

use App\Shared\Application\Privacy\PersonalDataSource;
use App\Shared\Infrastructure\Persistence\PersonalDataRows;
use Doctrine\DBAL\Connection;

/** Devis reçus (montants en euros) et décisions du client. */
final readonly class QuotePersonalData implements PersonalDataSource
{
    public function __construct(private Connection $connection)
    {
    }

    public function key(): string
    {
        return 'devis';
    }

    public function title(): string
    {
        return 'Devis reçus';
    }

    public function collect(string $userId): array
    {
        return PersonalDataRows::format($this->connection->fetchAllAssociative(<<<'SQL'
            SELECT q.number AS numero, r.number AS demande, a.company_name AS artisan, v.subject AS objet,
                   round(v.total_ttc_cents / 100.0, 2) AS montant_ttc_euros, q.status AS statut, q.sent_at AS recu_at,
                   q.viewed_at AS consulte_at, q.decided_at AS decision_at, q.decision_reason AS motif
            FROM quote.quote q
            JOIN request.quote_request r ON r.id = q.request_id
            JOIN quote.quote_version v ON v.quote_id = q.id AND v.version = q.current_version
            LEFT JOIN artisan.artisan_profile a ON a.id = q.artisan_id
            WHERE r.client_id = :u AND q.status <> 'draft' ORDER BY q.sent_at
            SQL, ['u' => $userId]));
    }
}
