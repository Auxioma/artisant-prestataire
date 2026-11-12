<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Infrastructure\Projection;

use App\ArtisanProfile\Application\Port\PortfolioStore;
use App\ArtisanProfile\Application\Port\ProfileContent;
use App\ArtisanProfile\Application\Portfolio\PortfolioProject;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

/** PR08.6 — projets et photos ; une photo n'est publique qu'une fois analysée (`shared.stored_file.status = available`). */
final readonly class DbalPortfolioStore implements PortfolioStore, ProfileContent
{
    public function __construct(private Connection $connection)
    {
    }

    public function projects(string $profileId, bool $publicOnly): array
    {
        $projects = [];
        foreach ($this->connection->fetchAllAssociative('SELECT * FROM artisan.portfolio_project WHERE artisan_id = :a ORDER BY position, created_at', ['a' => $profileId]) as $row) {
            $project = $this->hydrate($row);
            if ($publicOnly) {
                $photos = array_values(array_filter($project->photos, static fn (array $p): bool => $p['available']));
                if ([] === $photos) {
                    continue; // projet sans photo publiable : non affiché sur la fiche
                }
                $project = new PortfolioProject($project->id, $project->title, $project->description, $project->city, $project->doneOn, $project->jobId, $photos);
            }
            $projects[] = $project;
        }

        return $projects;
    }

    public function project(string $profileId, string $projectId): ?PortfolioProject
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM artisan.portfolio_project WHERE id = :id AND artisan_id = :a', ['id' => $projectId, 'a' => $profileId]);

        return false === $row ? null : $this->hydrate($row);
    }

    public function countProjects(string $profileId): int
    {
        return DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM artisan.portfolio_project WHERE artisan_id = :a', ['a' => $profileId]));
    }

    public function saveProject(string $id, string $profileId, string $title, ?string $description, ?string $city, ?\DateTimeImmutable $doneOn, ?string $jobId, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(
            'INSERT INTO artisan.portfolio_project (id, artisan_id, title, description, city, done_on, job_id, position, created_at, updated_at)
             VALUES (:id, :a, :title, :description, :city, :done, :job, (SELECT coalesce(max(position), -1) + 1 FROM artisan.portfolio_project WHERE artisan_id = :a), :at, :at)
             ON CONFLICT (id) DO UPDATE SET title = EXCLUDED.title, description = EXCLUDED.description, city = EXCLUDED.city, done_on = EXCLUDED.done_on, job_id = EXCLUDED.job_id, updated_at = EXCLUDED.updated_at
             WHERE artisan.portfolio_project.artisan_id = :a',
            ['id' => $id, 'a' => $profileId, 'title' => $title, 'description' => $description, 'city' => $city, 'done' => $doneOn?->format('Y-m-d'), 'job' => $jobId, 'at' => $at->format(\DATE_ATOM)],
        );
    }

    public function deleteProject(string $profileId, string $projectId): array
    {
        $files = array_map(DbalValue::string(...), $this->connection->fetchFirstColumn(
            'SELECT ph.file_id FROM artisan.portfolio_photo ph JOIN artisan.portfolio_project pr ON pr.id = ph.project_id WHERE pr.id = :id AND pr.artisan_id = :a',
            ['id' => $projectId, 'a' => $profileId],
        ));
        $this->connection->executeStatement('DELETE FROM artisan.portfolio_project WHERE id = :id AND artisan_id = :a', ['id' => $projectId, 'a' => $profileId]);

        return $files;
    }

    public function addPhoto(string $id, string $projectId, string $fileId, string $kind, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(
            'INSERT INTO artisan.portfolio_photo (id, project_id, file_id, kind, position, created_at)
             VALUES (:id, :p, :f, :kind, (SELECT coalesce(max(position), -1) + 1 FROM artisan.portfolio_photo WHERE project_id = :p), :at)',
            ['id' => $id, 'p' => $projectId, 'f' => $fileId, 'kind' => $kind, 'at' => $at->format(\DATE_ATOM)],
        );
    }

    public function deletePhoto(string $profileId, string $photoId): ?string
    {
        $file = $this->connection->fetchOne(
            'DELETE FROM artisan.portfolio_photo ph USING artisan.portfolio_project pr
             WHERE ph.id = :id AND pr.id = ph.project_id AND pr.artisan_id = :a RETURNING ph.file_id',
            ['id' => $photoId, 'a' => $profileId],
        );

        return false === $file ? null : DbalValue::string($file);
    }

    public function availablePhotos(string $profileId): int
    {
        return DbalValue::int($this->connection->fetchOne(
            "SELECT count(*) FROM artisan.portfolio_photo ph JOIN artisan.portfolio_project pr ON pr.id = ph.project_id
             JOIN shared.stored_file f ON f.id = ph.file_id AND f.status = 'available' WHERE pr.artisan_id = :a",
            ['a' => $profileId],
        ));
    }

    public function portfolioPhotos(string $profileId): int
    {
        return $this->availablePhotos($profileId);
    }

    public function ownerOfFile(string $fileId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT p.id, p.user_id FROM artisan.portfolio_photo ph JOIN artisan.portfolio_project pr ON pr.id = ph.project_id
             JOIN artisan.artisan_profile p ON p.id = pr.artisan_id WHERE ph.file_id = :f',
            ['f' => $fileId],
        );

        return false === $row ? null : ['profile_id' => DbalValue::string($row['id']), 'user_id' => DbalValue::string($row['user_id'])];
    }

    public function publicPhoto(string $photoId): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT ph.file_id, pr.artisan_id FROM artisan.portfolio_photo ph JOIN artisan.portfolio_project pr ON pr.id = ph.project_id
             JOIN artisan.artisan_profile p ON p.id = pr.artisan_id AND p.status = 'verified' AND p.hidden_at IS NULL
             WHERE ph.id = :id",
            ['id' => $photoId],
        );

        return false === $row ? null : ['file_id' => DbalValue::string($row['file_id']), 'profile_id' => DbalValue::string($row['artisan_id'])];
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): PortfolioProject
    {
        $photos = array_map(static fn (array $p): array => [
            'id' => DbalValue::string($p['id']),
            'file_id' => DbalValue::string($p['file_id']),
            'kind' => DbalValue::string($p['kind']),
            'available' => 'available' === $p['status'],
        ], $this->connection->fetchAllAssociative(
            'SELECT ph.id, ph.file_id, ph.kind, f.status FROM artisan.portfolio_photo ph JOIN shared.stored_file f ON f.id = ph.file_id WHERE ph.project_id = :p ORDER BY ph.position',
            ['p' => $row['id']],
        ));

        return new PortfolioProject(
            DbalValue::string($row['id']),
            DbalValue::string($row['title']),
            DbalValue::nullableString($row['description']),
            DbalValue::nullableString($row['city']),
            null === $row['done_on'] ? null : new \DateTimeImmutable(DbalValue::string($row['done_on'])),
            DbalValue::nullableString($row['job_id']),
            $photos,
        );
    }
}
