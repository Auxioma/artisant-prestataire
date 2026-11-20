<?php

declare(strict_types=1);

namespace App\Administration\Infrastructure\Logs;

use App\Administration\Application\Logs\AuditLogEntry;
use App\Administration\Application\Logs\AuditLogFilter;
use App\Administration\Application\Logs\AuditLogReader;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Lecture de `admin.audit_log`. Auteur : nom de l'administrateur, prénom et initiale d'un utilisateur (« Compte
 * effacé » après anonymisation) ou « Système ». L'adresse IP n'est jamais renvoyée en clair (deux octets masqués).
 */
final readonly class DbalAuditLogReader implements AuditLogReader
{
    private const string ACTOR = "CASE l.actor_type
            WHEN 'admin' THEN a.name
            WHEN 'user' THEN CASE WHEN u.anonymized_at IS NOT NULL THEN 'Compte effacé' ELSE nullif(trim(coalesce(u.first_name, '') || ' ' || coalesce(left(u.last_name, 1) || '.', '')), '') END
            ELSE 'Système' END";

    public function __construct(private Connection $connection)
    {
    }

    public function page(AuditLogFilter $filter, \DateTimeImmutable $now, int $offset, int $limit): array
    {
        [$where, $params, $types] = $this->where($filter, $now);
        $from = 'FROM admin.audit_log l
                 LEFT JOIN admin.admin_user a ON l.actor_type = \'admin\' AND a.id = l.actor_id
                 LEFT JOIN identity.user_account u ON l.actor_type = \'user\' AND u.id = l.actor_id
                 WHERE '.$where;
        $total = DbalValue::int($this->connection->fetchOne('SELECT count(*) '.$from, $params, $types));
        $rows = $this->connection->fetchAllAssociative(
            'SELECT l.id, l.occurred_at, l.actor_type, '.self::ACTOR.' AS actor, l.action, l.subject_type, l.subject_id, host(l.ip) AS ip, l.impersonator_id, l.context '
            .$from.' ORDER BY l.seq DESC LIMIT :limit OFFSET :offset',
            $params + ['limit' => $limit, 'offset' => $offset],
            $types,
        );

        return ['total' => $total, 'rows' => array_map(static function (array $r): AuditLogEntry {
            /** @var array<string, mixed>|mixed $context */
            $context = null === $r['context'] ? [] : DbalValue::json($r['context']);
            /** @var array<string, mixed> $context */
            $context = \is_array($context) ? $context : [];

            return new AuditLogEntry(
                DbalValue::string($r['id']),
                new \DateTimeImmutable(DbalValue::string($r['occurred_at'])),
                DbalValue::string($r['actor_type']),
                DbalValue::nullableString($r['actor']),
                DbalValue::string($r['action']),
                DbalValue::nullableString($r['subject_type']),
                DbalValue::nullableString($r['subject_id']),
                self::mask(DbalValue::nullableString($r['ip'])),
                null !== $r['impersonator_id'],
                $context,
            );
        }, $rows)];
    }

    public function modules(): array
    {
        return array_map(DbalValue::string(...), $this->connection->fetchFirstColumn("SELECT DISTINCT split_part(action, '.', 1) FROM admin.audit_log ORDER BY 1"));
    }

    public function admins(): array
    {
        $admins = [];
        foreach ($this->connection->fetchAllAssociative('SELECT id, name FROM admin.admin_user ORDER BY name') as $r) {
            $admins[DbalValue::string($r['id'])] = DbalValue::string($r['name']);
        }

        return $admins;
    }

    /** @return array{0: string, 1: array<string, mixed>, 2: array<string, ArrayParameterType>} */
    private function where(AuditLogFilter $filter, \DateTimeImmutable $now): array
    {
        $where = [];
        $params = [];
        $types = [];
        if (AuditLogFilter::VIEW_LOGINS === $filter->view) {
            $where[] = 'l.action IN (:logins)';
            $params['logins'] = AuditLogFilter::LOGIN_ACTIONS;
            $types['logins'] = ArrayParameterType::STRING;
        } else {
            $where[] = "l.actor_type = 'admin'";
        }
        if ('' !== $filter->search) {
            $where[] = '(l.action ILIKE :like OR l.subject_type ILIKE :like OR l.subject_id ILIKE :like OR a.name ILIKE :like)';
            $params['like'] = '%'.addcslashes($filter->search, '%_\\').'%';
        }
        if (null !== $filter->module) {
            $where[] = "split_part(l.action, '.', 1) = :module";
            $params['module'] = $filter->module;
        }
        if (null !== $filter->adminId) {
            $where[] = "l.actor_type = 'admin' AND l.actor_id = CAST(:admin AS uuid)";
            $params['admin'] = $filter->adminId;
        }
        if (null !== $filter->days) {
            $where[] = 'l.occurred_at >= :since';
            $params['since'] = $now->modify(\sprintf('-%d days', $filter->days))->format(\DATE_ATOM);
        }

        return [implode(' AND ', $where), $params, $types];
    }

    /** 91.168.23.12 → 91.168.•••.12 ; IPv6 : deux premiers groupes seulement. */
    private static function mask(?string $ip): ?string
    {
        if (null === $ip) {
            return null;
        }
        if (str_contains($ip, ':')) {
            return implode(':', \array_slice(explode(':', $ip), 0, 2)).':…';
        }
        $parts = explode('.', $ip);

        return 4 === \count($parts) ? $parts[0].'.'.$parts[1].'.•••.'.$parts[3] : '•••';
    }
}
