<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Infrastructure\Projection;

use App\ArtisanProfile\Application\Port\ProfileReviews;
use Doctrine\DBAL\Connection;

final readonly class DbalProfileReviews implements ProfileReviews
{
    public function __construct(private Connection $connection)
    {
    }

    public function record(string $id, string $profileId, string $section, array $content, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(
            'INSERT INTO artisan.profile_review (id, artisan_id, section, content, created_at) VALUES (:id, :artisan, :section, CAST(:content AS jsonb), :at)',
            ['id' => $id, 'artisan' => $profileId, 'section' => $section, 'content' => json_encode($content, \JSON_THROW_ON_ERROR), 'at' => $at->format(\DATE_ATOM)],
        );
    }
}
