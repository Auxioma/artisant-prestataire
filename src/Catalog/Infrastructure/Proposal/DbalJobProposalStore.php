<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Proposal;

use App\Catalog\Application\Proposal\JobProposalOf;
use App\Catalog\Application\Proposal\JobProposals;
use App\Catalog\Application\Proposal\JobProposalStore;
use App\Catalog\Application\Proposal\JobProposalView;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalJobProposalStore implements JobProposalStore, QueryHandler
{
    private const string SELECT = <<<'SQL'
        SELECT p.id, p.job_id, j.name AS job_name, c.name AS category_name, c.slug AS category_slug, p.new_category,
               p.proposed_job_name, p.proposed_category_name, p.status, f.name AS final_job_name, p.note, p.created_at,
               (SELECT count(*) FROM request.quote_request r WHERE r.job_id = p.job_id AND r.status = 'pending_moderation') AS pending_requests
        FROM catalog.job_proposal p
        JOIN catalog.job j ON j.id = p.job_id
        JOIN catalog.category c ON c.id = j.category_id
        LEFT JOIN catalog.job f ON f.id = p.final_job_id
        SQL;

    public function __construct(private Connection $connection)
    {
    }

    /** @return list<JobProposalView>|JobProposalView|int|null */
    public function __invoke(JobProposals|JobProposalOf $query): array|JobProposalView|int|null
    {
        if ($query instanceof JobProposalOf) {
            return $this->find($query->proposalId);
        }
        if ($query->countOnly) {
            return DbalValue::int($this->connection->fetchOne("SELECT count(DISTINCT job_id) FROM catalog.job_proposal WHERE status = 'pending'"));
        }
        // Une ligne par métier en attente (la proposition la plus ancienne), puis les dernières décisions.
        $pending = $this->connection->fetchAllAssociative(self::SELECT." WHERE p.status = 'pending' AND p.id = (SELECT p2.id FROM catalog.job_proposal p2 WHERE p2.job_id = p.job_id AND p2.status = 'pending' ORDER BY p2.created_at LIMIT 1) ORDER BY p.created_at");
        $recent = $this->connection->fetchAllAssociative(self::SELECT." WHERE p.status IN ('approved', 'merged', 'rejected') AND p.decided_by IS NOT NULL ORDER BY p.decided_at DESC LIMIT ".JobProposals::RECENT_DECISIONS);

        return array_map(self::view(...), [...$pending, ...$recent]);
    }

    public function add(string $id, string $jobId, string $categoryId, bool $newCategory, string $jobName, ?string $categoryName, ?string $userId, string $status, \DateTimeImmutable $at): void
    {
        $this->connection->insert('catalog.job_proposal', [
            'id' => $id, 'job_id' => $jobId, 'category_id' => $categoryId, 'new_category' => $newCategory ? 'true' : 'false',
            'proposed_job_name' => $jobName, 'proposed_category_name' => $categoryName, 'user_id' => $userId, 'status' => $status,
            'created_at' => $at->format(\DATE_ATOM),
        ]);
    }

    public function find(string $id): ?JobProposalView
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(self::SELECT.' WHERE p.id = :id', ['id' => $id]);

        return false === $row ? null : self::view($row);
    }

    public function decidePending(string $jobId, string $status, ?string $finalJobId, ?string $note, string $adminId, \DateTimeImmutable $at): int
    {
        return (int) $this->connection->executeStatement(
            "UPDATE catalog.job_proposal SET status = :status, final_job_id = :final, note = :note, decided_by = :admin, decided_at = :at WHERE job_id = :job AND status = 'pending'",
            ['status' => $status, 'final' => $finalJobId, 'note' => $note, 'admin' => $adminId, 'at' => $at->format(\DATE_ATOM), 'job' => $jobId],
        );
    }

    public function countSince(?string $userId, \DateTimeImmutable $since): int
    {
        return DbalValue::int(null === $userId
            ? $this->connection->fetchOne('SELECT count(*) FROM catalog.job_proposal WHERE user_id IS NULL AND created_at >= :since', ['since' => $since->format(\DATE_ATOM)])
            : $this->connection->fetchOne('SELECT count(*) FROM catalog.job_proposal WHERE user_id = :user AND created_at >= :since', ['user' => $userId, 'since' => $since->format(\DATE_ATOM)]));
    }

    /** @param array<string, mixed> $row */
    private static function view(array $row): JobProposalView
    {
        return new JobProposalView(
            DbalValue::string($row['id']),
            DbalValue::string($row['job_id']),
            DbalValue::string($row['job_name']),
            DbalValue::string($row['category_name']),
            DbalValue::string($row['category_slug']),
            true === $row['new_category'],
            DbalValue::string($row['proposed_job_name']),
            DbalValue::nullableString($row['proposed_category_name']),
            DbalValue::string($row['status']),
            DbalValue::nullableString($row['final_job_name']),
            DbalValue::nullableString($row['note']),
            new \DateTimeImmutable(DbalValue::string($row['created_at'])),
            DbalValue::int($row['pending_requests']),
        );
    }
}
