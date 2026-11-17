<?php

declare(strict_types=1);

namespace App\Identity\Application\Admin;

final readonly class ClientListPage
{
    /** @param list<array{id: string, name: string, email: string, phone: ?string, type: string, status: string, verified: bool, created_at: \DateTimeImmutable, last_login_at: ?\DateTimeImmutable}> $items */
    public function __construct(public array $items, public int $total, public int $page, public int $perPage)
    {
    }

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }
}
