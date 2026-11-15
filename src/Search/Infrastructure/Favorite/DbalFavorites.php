<?php

declare(strict_types=1);

namespace App\Search\Infrastructure\Favorite;

use App\Search\Application\Favorite\Favorites;
use App\Search\Application\Favorite\FavoriteView;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalFavorites implements Favorites
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(private Connection $connection)
    {
    }

    public function add(string $userId, string $artisanId, \DateTimeImmutable $at): bool
    {
        if (1 !== preg_match(self::UUID, $artisanId) || 1 !== preg_match(self::UUID, $userId)) {
            return false;
        }

        return 1 === (int) $this->connection->executeStatement(
            'INSERT INTO search.favorite (user_id, artisan_id, created_at) SELECT :u, v.artisan_id, :at FROM search.artisan_search_view v WHERE v.artisan_id = :a ON CONFLICT DO NOTHING',
            ['u' => $userId, 'a' => $artisanId, 'at' => $at->format('Y-m-d\TH:i:sP')],
        );
    }

    public function remove(string $userId, string $artisanId): void
    {
        if (1 === preg_match(self::UUID, $artisanId)) {
            $this->connection->executeStatement('DELETE FROM search.favorite WHERE user_id = :u AND artisan_id = :a', ['u' => $userId, 'a' => $artisanId]);
        }
    }

    public function has(string $userId, string $artisanId): bool
    {
        return 1 === preg_match(self::UUID, $artisanId) && 1 === preg_match(self::UUID, $userId)
            && false !== $this->connection->fetchOne('SELECT 1 FROM search.favorite WHERE user_id = :u AND artisan_id = :a', ['u' => $userId, 'a' => $artisanId]);
    }

    public function of(string $userId): array
    {
        if (1 !== preg_match(self::UUID, $userId)) {
            return [];
        }

        return array_map(static fn (array $r): FavoriteView => new FavoriteView(
            DbalValue::string($r['artisan_id']),
            DbalValue::string($r['name']),
            true === $r['active'],
            DbalValue::nullableString($r['commune_name']),
            DbalValue::nullableString($r['department_code']),
            self::uuids($r['job_ids']),
            null === $r['rating_avg'] ? null : (float) DbalValue::string($r['rating_avg']),
            DbalValue::int($r['reviews_count']),
            true === $r['available_soon'],
            true === $r['has_logo'],
            new \DateTimeImmutable(DbalValue::string($r['created_at'])),
        ), $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT f.artisan_id, f.created_at, (v.artisan_id IS NOT NULL) AS active,
                       coalesce(v.name, p.trade_name, p.company_name, 'Professionnel') AS name, v.commune_name, v.department_code,
                       coalesce(v.job_ids, '{}'::uuid[]) AS job_ids, v.rating_avg, coalesce(v.reviews_count, 0) AS reviews_count,
                       coalesce(v.available_soon, false) AS available_soon, (v.logo_file_id IS NOT NULL) AS has_logo
                FROM search.favorite f
                JOIN artisan.artisan_profile p ON p.id = f.artisan_id
                LEFT JOIN search.artisan_search_view v ON v.artisan_id = f.artisan_id
                WHERE f.user_id = :u
                ORDER BY f.created_at DESC
                SQL,
            ['u' => $userId],
        ));
    }

    /** @return list<string> */
    private static function uuids(mixed $array): array
    {
        $raw = trim(DbalValue::string($array), '{}');

        return '' === $raw ? [] : array_values(array_filter(explode(',', $raw), static fn (string $id): bool => 1 === preg_match(self::UUID, $id)));
    }
}
