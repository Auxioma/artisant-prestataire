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

    public function redirect(string $source, string $target, bool $prefix, string $origin): void
    {
        $this->connection->transactional(static function (Connection $c) use ($source, $target, $prefix, $origin): void {
            // Boucle : revenir à un ancien slug annule la redirection qui partait de la cible.
            $c->executeStatement('DELETE FROM content.redirect WHERE source_path = :target', ['target' => $target]);
            // Chaînes : tout ce qui pointait vers l'ancienne adresse pointe désormais vers la nouvelle.
            $c->executeStatement(
                'UPDATE content.redirect SET target_path = :target, updated_at = now() WHERE target_path = :source AND source_path <> :target',
                ['source' => $source, 'target' => $target],
            );
            $c->executeStatement(
                'INSERT INTO content.redirect (source_path, target_path, status_code, match_prefix, origin) VALUES (:source, :target, 301, :prefix, :origin)
                 ON CONFLICT (source_path) DO UPDATE SET target_path = EXCLUDED.target_path, match_prefix = EXCLUDED.match_prefix, origin = EXCLUDED.origin, updated_at = now()',
                ['source' => $source, 'target' => $target, 'prefix' => $prefix, 'origin' => $origin],
                ['prefix' => \Doctrine\DBAL\ParameterType::BOOLEAN],
            );
        });
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
