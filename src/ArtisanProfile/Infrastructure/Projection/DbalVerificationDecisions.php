<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Infrastructure\Projection;

use App\ArtisanProfile\Application\Port\VerificationDecisions;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalVerificationDecisions implements VerificationDecisions
{
    public function __construct(private Connection $connection)
    {
    }

    public function record(string $id, string $artisanId, string $adminId, string $decision, ?string $reasonCode, ?string $comment, \DateTimeImmutable $at): void
    {
        $this->connection->insert('artisan.verification_decision', [
            'id' => $id, 'artisan_id' => $artisanId, 'admin_id' => $adminId, 'decision' => $decision,
            'reason_code' => $reasonCode, 'comment' => $comment, 'created_at' => $at->format(\DATE_ATOM),
        ]);
    }

    public function history(string $artisanId): array
    {
        return array_map(static fn (array $r): array => [
            'decision' => DbalValue::string($r['decision']),
            'reason_code' => null === $r['reason_code'] ? null : DbalValue::string($r['reason_code']),
            'comment' => null === $r['comment'] ? null : DbalValue::string($r['comment']),
            'admin_id' => DbalValue::string($r['admin_id']),
            'created_at' => new \DateTimeImmutable(DbalValue::string($r['created_at'])),
        ], $this->connection->fetchAllAssociative('SELECT decision, reason_code, comment, admin_id, created_at FROM artisan.verification_decision WHERE artisan_id = :id ORDER BY created_at DESC', ['id' => $artisanId]));
    }
}
