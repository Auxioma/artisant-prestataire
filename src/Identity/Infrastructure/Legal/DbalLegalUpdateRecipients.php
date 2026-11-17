<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Legal;

use App\Identity\Application\Port\LegalUpdateRecipients;
use App\Identity\Domain\User\UserKind;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalLegalUpdateRecipients implements LegalUpdateRecipients
{
    public function __construct(private Connection $connection)
    {
    }

    public function batch(?UserKind $kind, ?string $afterId, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, email, first_name FROM identity.user_account
             WHERE status = 'active' AND (CAST(:kind AS text) IS NULL OR kind = :kind) AND (CAST(:after AS uuid) IS NULL OR id > CAST(:after AS uuid))
             ORDER BY id LIMIT :limit",
            ['kind' => $kind?->value, 'after' => $afterId, 'limit' => $limit],
        );

        return array_map(static fn (array $r): array => ['id' => DbalValue::string($r['id']), 'email' => DbalValue::string($r['email']), 'first_name' => DbalValue::string($r['first_name'])], $rows);
    }
}
