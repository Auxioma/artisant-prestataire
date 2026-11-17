<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Redirect;

use App\Content\Application\Port\RedirectStore;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalRedirectStore implements RedirectStore
{
    public function __construct(private Connection $connection)
    {
    }

    public function redirect(string $source, string $target, bool $prefix, string $origin, int $status = 301): void
    {
        $this->connection->transactional(static function (Connection $c) use ($source, $target, $prefix, $origin, $status): void {
            // Boucle : revenir à un ancien slug annule la redirection qui partait de la cible.
            $c->executeStatement('DELETE FROM content.redirect WHERE source_path = :target', ['target' => $target]);
            // Chaînes : tout ce qui pointait vers l'ancienne adresse pointe désormais vers la nouvelle.
            $c->executeStatement(
                'UPDATE content.redirect SET target_path = :target, updated_at = now() WHERE target_path = :source AND source_path <> :target',
                ['source' => $source, 'target' => $target],
            );
            $c->executeStatement(
                'INSERT INTO content.redirect (source_path, target_path, status_code, match_prefix, origin) VALUES (:source, :target, :status, :prefix, :origin)
                 ON CONFLICT (source_path) DO UPDATE SET target_path = EXCLUDED.target_path, status_code = EXCLUDED.status_code, match_prefix = EXCLUDED.match_prefix, origin = EXCLUDED.origin, updated_at = now()',
                ['source' => $source, 'target' => $target, 'status' => $status, 'prefix' => $prefix, 'origin' => $origin],
                ['prefix' => \Doctrine\DBAL\ParameterType::BOOLEAN],
            );
        });
    }

    public function list(string $search, int $offset, int $limit): array
    {
        $where = "(CAST(:q AS text) = '' OR source_path ILIKE :like OR target_path ILIKE :like)";
        $params = ['q' => $search, 'like' => '%'.addcslashes($search, '%_\\').'%'];
        $total = DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM content.redirect WHERE '.$where, $params));
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM content.redirect WHERE '.$where.' ORDER BY updated_at DESC, id DESC OFFSET :offset LIMIT :limit',
            $params + ['offset' => max(0, $offset), 'limit' => max(1, $limit)],
        );

        return ['total' => $total, 'items' => array_map(static fn (array $r): array => [
            'id' => DbalValue::int($r['id']),
            'source' => DbalValue::string($r['source_path']),
            'target' => DbalValue::string($r['target_path']),
            'status' => DbalValue::int($r['status_code']),
            'prefix' => true === $r['match_prefix'],
            'origin' => DbalValue::string($r['origin']),
            'hits' => DbalValue::int($r['hits']),
            'updated_at' => DbalValue::string($r['updated_at']),
        ], $rows)];
    }

    public function delete(int $id): void
    {
        $this->connection->executeStatement('DELETE FROM content.redirect WHERE id = :id', ['id' => $id]);
    }

    public function resolve(string $path): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, source_path, target_path, status_code, match_prefix FROM content.redirect
             WHERE source_path = :path OR (match_prefix AND :path LIKE replace(replace(source_path, \'%\', \'\%\'), \'_\', \'\_\') || \'%\')
             ORDER BY (source_path = :path) DESC, length(source_path) DESC LIMIT 1',
            ['path' => $path],
        );
        if (false === $row) {
            return null;
        }
        $this->connection->executeStatement('UPDATE content.redirect SET hits = hits + 1 WHERE id = :id', ['id' => $row['id']]);

        $source = DbalValue::string($row['source_path']);
        $target = DbalValue::string($row['target_path']);
        if (true === $row['match_prefix'] && $path !== $source) {
            $target .= substr($path, \strlen($source));
        }

        return ['target' => $target, 'status' => DbalValue::int($row['status_code'])];
    }
}
