<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Query;

use App\Catalog\Application\Query\FindJobs;
use App\Catalog\Application\Query\JobIndexEntries;
use App\Catalog\Application\Query\JobIndexEntry;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class DbalJobQueriesHandler implements QueryHandler
{
    private const string SELECT = <<<'SQL'
        SELECT j.id, j.name, j.slug, j.synonyms, j.active, root.name AS category_name, root.slug AS category_slug,
               (j.active AND c.active AND root.active) AS searchable
        FROM catalog.job j
        JOIN catalog.category c ON c.id = j.category_id
        JOIN catalog.category root ON root.id = COALESCE(c.parent_id, c.id)
        SQL;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<JobIndexEntry>
     */
    public function __invoke(JobIndexEntries|FindJobs $query): array
    {
        if ($query instanceof JobIndexEntries) {
            $rows = null === $query->jobIds
                ? $this->connection->fetchAllAssociative(self::SELECT.' ORDER BY root.position, j.position')
                : $this->connection->fetchAllAssociative(self::SELECT.' WHERE j.id IN (:ids)', ['ids' => $query->jobIds], ['ids' => ArrayParameterType::STRING]);

            return array_map($this->entry(...), $rows);
        }

        $term = trim($query->term);
        if (mb_strlen($term) < 2) {
            return [];
        }
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
        $rows = $this->connection->fetchAllAssociative(
            self::SELECT.<<<'SQL'
                 WHERE j.active AND c.active AND root.active AND (
                    catalog.immutable_unaccent(lower(j.name)) LIKE catalog.immutable_unaccent(lower(:contains))
                    OR EXISTS (SELECT 1 FROM jsonb_array_elements_text(j.synonyms) s WHERE catalog.immutable_unaccent(lower(s)) LIKE catalog.immutable_unaccent(lower(:contains)))
                 )
                 ORDER BY (catalog.immutable_unaccent(lower(j.name)) LIKE catalog.immutable_unaccent(lower(:prefix))) DESC, j.name
                 LIMIT :limit
                SQL,
            ['contains' => '%'.$escaped.'%', 'prefix' => $escaped.'%', 'limit' => max(1, min($query->limit, 50))],
        );

        return array_map($this->entry(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function entry(array $row): JobIndexEntry
    {
        $synonyms = DbalValue::json($row['synonyms']);

        return new JobIndexEntry(
            DbalValue::string($row['id']),
            DbalValue::string($row['name']),
            DbalValue::string($row['slug']),
            \is_array($synonyms) ? array_values(array_filter($synonyms, \is_string(...))) : [],
            DbalValue::string($row['category_name']),
            DbalValue::string($row['category_slug']),
            true === $row['searchable'],
        );
    }
}
