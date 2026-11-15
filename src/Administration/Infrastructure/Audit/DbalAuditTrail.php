<?php

declare(strict_types=1);

namespace App\Administration\Infrastructure\Audit;

use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

/**
 * Journal d'audit append-only (trigger + chaînage de hash). Un verrou consultatif transactionnel sérialise
 * les insertions pour garantir une chaîne linéaire.
 */
final readonly class DbalAuditTrail implements AuditTrail
{
    /** Clé arbitraire et stable du verrou consultatif PostgreSQL dédié à la chaîne d'audit. */
    public const int CHAIN_LOCK_KEY = 726_384_001;

    public function __construct(private Connection $connection)
    {
    }

    public function record(AuditRecord $record): void
    {
        $this->connection->transactional(static function (Connection $connection) use ($record): void {
            $connection->executeStatement('SELECT pg_advisory_xact_lock(:k)', ['k' => self::CHAIN_LOCK_KEY]);
            $previousHash = DbalValue::nullableString($connection->fetchOne('SELECT hash FROM admin.audit_log ORDER BY seq DESC LIMIT 1'));

            $connection->executeStatement(
                'INSERT INTO admin.audit_log (id, occurred_at, actor_type, actor_id, impersonator_id, action, subject_type, subject_id, ip, context, canonical, prev_hash, hash)
                 VALUES (:id, :occurred_at, :actor_type, :actor_id, :impersonator_id, :action, :subject_type, :subject_id, :ip, :context, :canonical, :prev_hash, :hash)',
                [
                    'id' => $record->id,
                    'occurred_at' => $record->occurredAt->format('Y-m-d H:i:s.uP'),
                    'actor_type' => $record->actorType,
                    'actor_id' => $record->actorId,
                    'impersonator_id' => $record->impersonatorId,
                    'action' => $record->action,
                    'subject_type' => $record->subjectType,
                    'subject_id' => $record->subjectId,
                    'ip' => $record->ip,
                    'context' => json_encode($record->context, \JSON_THROW_ON_ERROR),
                    'canonical' => $record->canonical(),
                    'prev_hash' => $previousHash,
                    'hash' => $record->seal($previousHash),
                ],
            );
        });
    }

    public function verifyChain(): ?string
    {
        // Après une purge de rétention, la chaîne reprend à l'empreinte de la dernière entrée supprimée (point d'ancrage).
        $previousHash = DbalValue::nullableString($this->connection->fetchOne('SELECT last_hash FROM admin.audit_anchor ORDER BY purged_through_seq DESC LIMIT 1') ?: null);
        foreach ($this->connection->iterateAssociative('SELECT id, canonical, prev_hash, hash FROM admin.audit_log ORDER BY seq') as $row) {
            $hash = DbalValue::string($row['hash']);
            $expected = hash('sha256', ($previousHash ?? '').'|'.DbalValue::string($row['canonical']));
            if (DbalValue::nullableString($row['prev_hash']) !== $previousHash || $hash !== $expected) {
                return DbalValue::string($row['id']);
            }
            $previousHash = $hash;
        }

        return null;
    }
}
