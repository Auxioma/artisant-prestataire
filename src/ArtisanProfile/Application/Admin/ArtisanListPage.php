<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Admin;

final readonly class ArtisanListPage
{
    /** @param list<array{id: string, user_id: string, name: string, siret: ?string, jobs: string, department: ?string, status: string, hidden: bool, completion: int, created_at: \DateTimeImmutable, credits: ?int}> $items */
    public function __construct(public array $items, public int $total, public int $page, public int $perPage)
    {
    }

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }
}
