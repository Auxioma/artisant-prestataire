<?php

declare(strict_types=1);

namespace App\Administration\Infrastructure\Audit;

use App\Administration\Application\Retention\AuditRetention;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

/**
 * Purge de tête : les entrées antérieures à la date sont supprimées (autorisation limitée à la transaction) et
 * l'empreinte de la dernière supprimée devient le point d'ancrage de la vérification de la chaîne.
 */
final readonly class DbalAuditRetention implements AuditRetention
{
    public function __construct(private Connection $connection)
    {
    }

    public function purgeBefore(\DateTimeImmutable $before, \DateTimeImmutable $now): int
    {
        return $this->connection->transactional(static function (Connection $c) use ($before, $now): int {
            $c->executeStatement('SELECT pg_advisory_xact_lock(:k)', ['k' => DbalAuditTrail::CHAIN_LOCK_KEY]);
            $last = $c->fetchAssociative('SELECT seq, hash FROM admin.audit_log WHERE occurred_at < :b ORDER BY seq DESC LIMIT 1', ['b' => $before->format(\DATE_ATOM)]);
            if (false === $last) {
                return 0;
            }
            $c->executeStatement("SELECT set_config('app.audit_retention', 'on', true)");
            $count = (int) $c->executeStatement('DELETE FROM admin.audit_log WHERE seq <= :s', ['s' => $last['seq']]);
            $c->executeStatement("SELECT set_config('app.audit_retention', 'off', true)");
            $c->insert('admin.audit_anchor', ['purged_through_seq' => $last['seq'], 'last_hash' => DbalValue::string($last['hash']), 'purged_count' => $count, 'purged_at' => $now->format(\DATE_ATOM)]);

            return $count;
        });
    }
}
