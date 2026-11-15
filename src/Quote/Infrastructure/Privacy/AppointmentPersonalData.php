<?php

declare(strict_types=1);

namespace App\Quote\Infrastructure\Privacy;

use App\Shared\Application\Privacy\PersonalDataSource;
use App\Shared\Infrastructure\Persistence\PersonalDataRows;
use Doctrine\DBAL\Connection;

final readonly class AppointmentPersonalData implements PersonalDataSource
{
    public function __construct(private Connection $connection)
    {
    }

    public function key(): string
    {
        return 'rendez_vous';
    }

    public function title(): string
    {
        return 'Rendez-vous';
    }

    public function collect(string $userId): array
    {
        return PersonalDataRows::format($this->connection->fetchAllAssociative(<<<'SQL'
            SELECT q.number AS devis, ap.starts_at AS debut_at, ap.ends_at AS fin_at, ap.status AS statut, ap.proposed_by AS propose_par,
                   ap.note, ap.cancel_reason AS motif_annulation, ap.created_at
            FROM quote.appointment ap JOIN quote.quote q ON q.id = ap.quote_id JOIN request.quote_request r ON r.id = q.request_id
            WHERE r.client_id = :u ORDER BY ap.starts_at
            SQL, ['u' => $userId]));
    }
}
