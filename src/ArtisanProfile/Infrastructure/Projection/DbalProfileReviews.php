<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Infrastructure\Projection;

use App\ArtisanProfile\Application\Port\ProfileReviews;
use App\Shared\Infrastructure\Persistence\DbalValue;
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

    public function pending(int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT r.id, r.artisan_id, coalesce(p.trade_name, p.company_name, 'Artisan') AS artisan_name, r.section, r.content, r.created_at
             FROM artisan.profile_review r JOIN artisan.artisan_profile p ON p.id = r.artisan_id
             WHERE r.status = 'pending' ORDER BY r.created_at LIMIT :limit",
            ['limit' => $limit],
            ['limit' => \Doctrine\DBAL\ParameterType::INTEGER],
        );

        return array_map(static fn (array $r): array => [
            'id' => DbalValue::string($r['id']),
            'artisan_id' => DbalValue::string($r['artisan_id']),
            'artisan_name' => DbalValue::string($r['artisan_name']),
            'section' => DbalValue::string($r['section']),
            'content' => self::content($r['content']),
            'created_at' => new \DateTimeImmutable(DbalValue::string($r['created_at'])),
        ], $rows);
    }

    public function find(string $id): ?array
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id)) {
            return null;
        }
        $r = $this->connection->fetchAssociative('SELECT artisan_id, section, content, status FROM artisan.profile_review WHERE id = :id FOR UPDATE', ['id' => $id]);

        return false === $r ? null : ['artisan_id' => DbalValue::string($r['artisan_id']), 'section' => DbalValue::string($r['section']), 'content' => self::content($r['content']), 'status' => DbalValue::string($r['status'])];
    }

    public function decide(string $id, bool $approved, string $adminId, ?string $reason, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(
            'UPDATE artisan.profile_review SET status = :status, reviewed_at = :at, reviewed_by = :admin, reason = :reason WHERE id = :id',
            ['status' => $approved ? 'approved' : 'rejected', 'at' => $at->format(\DATE_ATOM), 'admin' => $adminId, 'reason' => $reason, 'id' => $id],
        );
    }

    /** @return array<string, mixed> */
    private static function content(mixed $value): array
    {
        $decoded = DbalValue::json($value);
        $content = [];
        foreach (\is_array($decoded) ? $decoded : [] as $k => $v) {
            $content[(string) $k] = $v;
        }

        return $content;
    }
}
