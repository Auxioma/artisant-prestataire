<?php

declare(strict_types=1);

namespace App\Review\Infrastructure\Moderation;

use App\Shared\Application\Moderation\ReportableContent;
use App\Shared\Application\Moderation\ReportedContent;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

/** P10.9 « Signaler cet avis » : avis publié uniquement. */
final readonly class ReportableReview implements ReportableContent
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(private Connection $connection)
    {
    }

    public function type(): string
    {
        return 'avis';
    }

    public function label(): string
    {
        return 'Avis';
    }

    public function describe(string $id, string $viewerUserId): ?ReportedContent
    {
        if (1 !== preg_match(self::UUID, $id)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(<<<'SQL'
            SELECT r.rating, r.title, r.body, r.client_user_id, coalesce(p.trade_name, p.company_name, 'Professionnel') AS artisan
            FROM review.review r LEFT JOIN artisan.artisan_profile p ON p.id = r.artisan_id
            WHERE r.id = :id AND r.status = 'published'
            SQL, ['id' => $id]);
        if (false === $row) {
            return null;
        }
        $title = DbalValue::nullableString($row['title']);

        return new ReportedContent(
            'Avis '.DbalValue::int($row['rating']).'/5 sur '.DbalValue::string($row['artisan']),
            (null === $title ? '' : '« '.$title.' » ').DbalValue::string($row['body']),
            DbalValue::nullableString($row['client_user_id']),
        );
    }

    public function adminRoute(string $id): ?array
    {
        return null;
    }
}
