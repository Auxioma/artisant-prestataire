<?php

declare(strict_types=1);

namespace App\QuoteRequest\Infrastructure\Moderation;

use App\Shared\Application\Moderation\ReportableContent;
use App\Shared\Application\Moderation\ReportedContent;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

/** PR03 « Signaler cette demande » : seul un artisan qui l'a reçue peut la signaler (demande frauduleuse, hors sujet…). */
final readonly class ReportableRequest implements ReportableContent
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(private Connection $connection)
    {
    }

    public function type(): string
    {
        return 'demande';
    }

    public function label(): string
    {
        return 'Demande';
    }

    public function describe(string $id, string $viewerUserId): ?ReportedContent
    {
        if (1 !== preg_match(self::UUID, $id) || 1 !== preg_match(self::UUID, $viewerUserId)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(<<<'SQL'
            SELECT r.number, r.description, r.postal_code, r.client_id, j.name AS job
            FROM request.quote_request r
            JOIN request.request_dispatch d ON d.request_id = r.id
            JOIN artisan.artisan_profile p ON p.id = d.artisan_id AND p.user_id = :viewer
            LEFT JOIN catalog.job j ON j.id = r.job_id
            WHERE r.id = :id
            SQL, ['id' => $id, 'viewer' => $viewerUserId]);
        if (false === $row) {
            return null;
        }

        return new ReportedContent(
            'Demande '.DbalValue::string($row['number']).' · '.(DbalValue::nullableString($row['job']) ?? 'métier').' ('.DbalValue::string($row['postal_code']).')',
            DbalValue::string($row['description']),
            DbalValue::nullableString($row['client_id']),
        );
    }

    /** @return array{route: string, parameters: array<string, string>} */
    public function adminRoute(string $id): array
    {
        return ['route' => 'admin_requests', 'parameters' => ['demande' => $id]];
    }
}
