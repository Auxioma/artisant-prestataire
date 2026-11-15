<?php

declare(strict_types=1);

namespace App\Notification\Application\Admin;

final readonly class DeliveryLogPage
{
    public const array STATUSES = ['queued' => 'En file', 'sent' => 'Envoyé', 'delivered' => 'Délivré', 'opened' => 'Ouvert', 'bounced' => 'Rebond', 'complained' => 'Plainte', 'failed' => 'Échec', 'skipped' => 'Non envoyé'];

    /** @param list<array{id: string, code: string, version: int, channel: string, recipient: ?string, status: string, error: ?string, at: \DateTimeImmutable, updatedAt: \DateTimeImmutable}> $rows */
    public function __construct(public array $rows, public int $total, public int $page, public int $perPage)
    {
    }

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }
}
