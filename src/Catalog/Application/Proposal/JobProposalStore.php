<?php

declare(strict_types=1);

namespace App\Catalog\Application\Proposal;

interface JobProposalStore
{
    public function add(string $id, string $jobId, string $categoryId, bool $newCategory, string $jobName, ?string $categoryName, ?string $userId, string $status, \DateTimeImmutable $at): void;

    public function find(string $id): ?JobProposalView;

    /** Clôture toutes les propositions en attente du métier ; renvoie le nombre de propositions décidées. */
    public function decidePending(string $jobId, string $status, ?string $finalJobId, ?string $note, string $adminId, \DateTimeImmutable $at): int;

    /** Garde-fou anti-abus : propositions créées depuis `$since` par ce compte (ou par tous les visiteurs si null). */
    public function countSince(?string $userId, \DateTimeImmutable $since): int;
}
