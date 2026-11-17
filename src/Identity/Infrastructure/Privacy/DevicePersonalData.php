<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Privacy;

use App\Shared\Application\Privacy\PersonalDataSource;
use App\Shared\Infrastructure\Persistence\PersonalDataRows;
use Doctrine\DBAL\Connection;

final readonly class DevicePersonalData implements PersonalDataSource
{
    public function __construct(private Connection $connection)
    {
    }

    public function key(): string
    {
        return 'appareils';
    }

    public function title(): string
    {
        return 'Appareils de connexion';
    }

    public function collect(string $userId): array
    {
        return PersonalDataRows::format($this->connection->fetchAllAssociative(
            'SELECT description AS appareil, first_seen_at AS premiere_connexion_at, last_seen_at AS derniere_connexion_at, revoked_at AS deconnecte_at FROM identity.session_device WHERE user_id = :u ORDER BY last_seen_at DESC',
            ['u' => $userId],
        ));
    }
}
