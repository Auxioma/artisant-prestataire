<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Persistence;

use App\Notification\Application\Port\InAppNotifications;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class DbalInAppNotifications implements InAppNotifications
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const int LINK_MAX = 500;

    public function __construct(private Connection $connection)
    {
    }

    public function add(string $id, string $userId, string $code, string $body, ?string $link, \DateTimeImmutable $at): void
    {
        $this->connection->insert('notification.in_app', [
            'id' => $id,
            'user_id' => $userId,
            'code' => $code,
            'body' => $body,
            'link' => self::path($link),
            'created_at' => $at->format(\DATE_ATOM),
        ]);
    }

    /**
     * Les modules fournissent des liens absolus (e-mails) : seul le chemin de l'application est conservé (lien interne,
     * jamais vers un autre site).
     */
    private static function path(?string $link): ?string
    {
        if (null === $link || '' === $link) {
            return null;
        }
        if (!str_starts_with($link, '/')) {
            $parts = parse_url($link);
            if (false === $parts || !isset($parts['path'])) {
                return null;
            }
            $link = $parts['path'].(isset($parts['query']) ? '?'.$parts['query'] : '').(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
        }

        return str_starts_with($link, '/') && !str_starts_with($link, '//') && mb_strlen($link) <= self::LINK_MAX ? $link : null;
    }

    public function page(string $userId, ?array $codes, array $excluded, int $limit, int $offset): array
    {
        if (1 !== preg_match(self::UUID, $userId)) {
            return ['rows' => [], 'total' => 0];
        }
        $where = 'user_id = :u';
        $params = ['u' => $userId, 'l' => $limit, 'o' => $offset];
        $types = ['l' => ParameterType::INTEGER, 'o' => ParameterType::INTEGER];
        if (null !== $codes) {
            $where .= ' AND code IN (:codes)';
            $params['codes'] = $codes;
            $types['codes'] = ArrayParameterType::STRING;
        }
        if ([] !== $excluded) {
            $where .= ' AND code NOT IN (:excluded)';
            $params['excluded'] = $excluded;
            $types['excluded'] = ArrayParameterType::STRING;
        }
        $rows = $this->connection->fetchAllAssociative('SELECT id, code, body, link, created_at, read_at, count(*) OVER () AS total FROM notification.in_app WHERE '.$where.' ORDER BY created_at DESC, id DESC LIMIT :l OFFSET :o', $params, $types);

        return [
            'rows' => array_map(static fn (array $r): array => ['id' => DbalValue::string($r['id']), 'code' => DbalValue::string($r['code']), 'body' => DbalValue::string($r['body']), 'link' => DbalValue::nullableString($r['link']), 'at' => new \DateTimeImmutable(DbalValue::string($r['created_at'])), 'read' => null !== $r['read_at']], $rows),
            'total' => [] === $rows ? 0 : DbalValue::int($rows[0]['total']),
        ];
    }

    public function markRead(string $userId, ?string $id, \DateTimeImmutable $at): void
    {
        if (null !== $id && 1 !== preg_match(self::UUID, $id)) {
            return;
        }
        $this->connection->executeStatement(
            'UPDATE notification.in_app SET read_at = :at WHERE user_id = :u AND read_at IS NULL'.(null === $id ? '' : ' AND id = :id'),
            ['at' => $at->format(\DATE_ATOM), 'u' => $userId] + (null === $id ? [] : ['id' => $id]),
        );
    }

    public function link(string $userId, string $id): ?string
    {
        if (1 !== preg_match(self::UUID, $id)) {
            return null;
        }
        $link = $this->connection->fetchOne('SELECT link FROM notification.in_app WHERE id = :id AND user_id = :u', ['id' => $id, 'u' => $userId]);

        return \is_string($link) ? $link : null;
    }

    public function purgeBefore(\DateTimeImmutable $before): int
    {
        return (int) $this->connection->executeStatement('DELETE FROM notification.in_app WHERE created_at < :b', ['b' => $before->format(\DATE_ATOM)]);
    }

    public function unreadCount(string $userId): int
    {
        return DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM notification.in_app WHERE user_id = :u AND read_at IS NULL', ['u' => $userId]));
    }

    public function forget(string $userId): void
    {
        $this->connection->executeStatement('DELETE FROM notification.in_app WHERE user_id = :u', ['u' => $userId]);
    }
}
