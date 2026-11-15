<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Infrastructure\Moderation;

use App\Shared\Application\Moderation\ReportableContent;
use App\Shared\Application\Moderation\ReportedContent;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

/** P10 « Signaler ce profil » : fiche d'un artisan vérifié (publique). */
final readonly class ReportableProfile implements ReportableContent
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(private Connection $connection)
    {
    }

    public function type(): string
    {
        return 'profil';
    }

    public function label(): string
    {
        return 'Profil';
    }

    public function describe(string $id, string $viewerUserId): ?ReportedContent
    {
        if (1 !== preg_match(self::UUID, $id)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(
            "SELECT coalesce(trade_name, company_name) AS name, siret, hq_city, short_description, user_id FROM artisan.artisan_profile WHERE id = :id AND status = 'verified'",
            ['id' => $id],
        );
        if (false === $row) {
            return null;
        }

        return new ReportedContent(
            'Profil de '.DbalValue::string($row['name']),
            trim('SIRET '.DbalValue::nullableString($row['siret']).' · '.DbalValue::nullableString($row['hq_city'])."\n".DbalValue::nullableString($row['short_description'])),
            DbalValue::nullableString($row['user_id']),
        );
    }

    /** @return array{route: string, parameters: array<string, string>} */
    public function adminRoute(string $id): array
    {
        return ['route' => 'admin_artisans_show', 'parameters' => ['id' => $id]];
    }
}
