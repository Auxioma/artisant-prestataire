<?php

declare(strict_types=1);

namespace App\QuoteRequest\Infrastructure\Privacy;

use App\Shared\Application\Privacy\PersonalDataSource;
use App\Shared\Infrastructure\Persistence\PersonalDataRows;
use Doctrine\DBAL\Connection;

/** Demandes déposées et compléments (l'adresse précise, chiffrée, n'est pas reprise : seul le code postal l'est). */
final readonly class RequestPersonalData implements PersonalDataSource
{
    public function __construct(private Connection $connection)
    {
    }

    public function key(): string
    {
        return 'demandes';
    }

    public function title(): string
    {
        return 'Demandes de devis';
    }

    public function collect(string $userId): array
    {
        return PersonalDataRows::format($this->connection->fetchAllAssociative(<<<'SQL'
            SELECT r.number AS numero, r.status AS statut, r.project_type AS type_de_projet, r.description, r.answers AS reponses,
                   r.budget_range AS budget, r.timing AS delai, r.desired_date, r.postal_code AS code_postal, r.submitted_at AS deposee_at,
                   COALESCE((SELECT json_agg(json_build_object('date', a.created_at, 'texte', a.body) ORDER BY a.created_at) FROM request.request_addendum a WHERE a.request_id = r.id), '[]') AS complements
            FROM request.quote_request r WHERE r.client_id = :u ORDER BY r.submitted_at
            SQL, ['u' => $userId]), ['reponses', 'complements']);
    }
}
