<?php

declare(strict_types=1);

namespace App\Search\Infrastructure\Privacy;

use App\Shared\Application\Privacy\PersonalDataSource;
use App\Shared\Infrastructure\Persistence\PersonalDataRows;
use Doctrine\DBAL\Connection;

final readonly class FavoritePersonalData implements PersonalDataSource
{
    public function __construct(private Connection $connection)
    {
    }

    public function key(): string
    {
        return 'favoris';
    }

    public function title(): string
    {
        return 'Favoris';
    }

    public function collect(string $userId): array
    {
        return PersonalDataRows::format($this->connection->fetchAllAssociative(
            'SELECT a.company_name AS artisan, f.created_at AS ajoute_at FROM search.favorite f JOIN artisan.artisan_profile a ON a.id = f.artisan_id WHERE f.user_id = :u ORDER BY f.created_at',
            ['u' => $userId],
        ));
    }
}
