<?php

declare(strict_types=1);

namespace App\Review\Infrastructure\Privacy;

use App\Shared\Application\Privacy\PersonalDataSource;
use App\Shared\Infrastructure\Persistence\PersonalDataRows;
use Doctrine\DBAL\Connection;

final readonly class ReviewPersonalData implements PersonalDataSource
{
    public function __construct(private Connection $connection)
    {
    }

    public function key(): string
    {
        return 'avis';
    }

    public function title(): string
    {
        return 'Avis déposés';
    }

    public function collect(string $userId): array
    {
        return PersonalDataRows::format($this->connection->fetchAllAssociative(<<<'SQL'
            SELECT a.company_name AS artisan, r.rating AS note, r.sub_ratings AS notes_detaillees, r.title AS titre, r.body AS texte,
                   r.recommends AS recommande, r.service_date AS date_prestation_on, r.status AS statut, r.reply_body AS reponse_artisan,
                   r.created_at
            FROM review.review r LEFT JOIN artisan.artisan_profile a ON a.id = r.artisan_id
            WHERE r.client_user_id = :u ORDER BY r.created_at
            SQL, ['u' => $userId]), ['notes_detaillees']);
    }
}
