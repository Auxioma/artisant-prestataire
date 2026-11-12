<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Query;

use App\Identity\Application\Admin\ClientList;
use App\Identity\Application\Admin\ClientListPage;
use App\Identity\Application\Admin\ClientRecord;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

/**
 * BO02 — requêtes paramétrées (cadrage §5.3). Le téléphone n'est jamais renvoyé en clair à la liste ni à la fiche
 * (masqué : 06 •• •• •• 78) ; les comptes anonymisés apparaissent sous le statut « supprimé ».
 */
final readonly class DbalClientAdminQueriesHandler implements QueryHandler
{
    private const int MAX_PER_PAGE = 100;
    private const array STATUSES = ['active', 'pending_email', 'suspended', 'deletion_requested', 'anonymized'];
    private const string SELECT = <<<'SQL'
        SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.status, u.email_verified_at IS NOT NULL AS verified,
               u.created_at, u.last_login_at, coalesce(c.type, 'particulier') AS type, c.company_name, c.postal_code, c.newsletter_opt_in_at
        FROM identity.user_account u LEFT JOIN identity.client_profile c ON c.user_id = u.id
        SQL;

    public function __construct(private Connection $connection)
    {
    }

    /** @return ClientListPage|array<string, mixed>|null */
    public function __invoke(ClientList|ClientRecord $query): ClientListPage|array|null
    {
        return $query instanceof ClientList ? $this->list($query) : $this->record($query->userId);
    }

    private function list(ClientList $query): ClientListPage
    {
        $where = ["u.kind = 'client'"];
        $params = [];
        $term = trim($query->search);
        if ('' !== $term) {
            $where[] = "(lower(u.first_name || ' ' || u.last_name) LIKE :term OR u.email_canonical LIKE :term OR u.phone LIKE :digits)";
            $params['term'] = '%'.mb_strtolower(str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term)).'%';
            $digits = (string) preg_replace('/\D/', '', $term);
            $params['digits'] = '' === $digits ? '-' : '%'.(str_starts_with($digits, '0') ? substr($digits, 1) : $digits).'%';
        }
        if (\in_array($query->status, self::STATUSES, true)) {
            $where[] = 'u.status = :status';
            $params['status'] = $query->status;
        }
        if (\in_array($query->type, ['particulier', 'professionnel'], true)) {
            $where[] = "coalesce(c.type, 'particulier') = :type";
            $params['type'] = $query->type;
        }
        $conditions = implode(' AND ', $where);
        $perPage = max(1, min($query->perPage, self::MAX_PER_PAGE));
        $page = max(1, $query->page);
        $total = DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM identity.user_account u LEFT JOIN identity.client_profile c ON c.user_id = u.id WHERE '.$conditions, $params));
        $rows = $this->connection->fetchAllAssociative(self::SELECT.' WHERE '.$conditions.' ORDER BY u.created_at DESC LIMIT :limit OFFSET :offset', $params + ['limit' => $perPage, 'offset' => ($page - 1) * $perPage]);

        return new ClientListPage(array_map(self::row(...), $rows), $total, $page, $perPage);
    }

    /** @return array<string, mixed>|null */
    private function record(string $userId): ?array
    {
        if (1 !== preg_match('/^[0-9a-f-]{36}$/', $userId)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(self::SELECT." WHERE u.id = :id AND u.kind = 'client'", ['id' => $userId]);
        if (false === $row) {
            return null;
        }

        return self::row($row) + [
            'company' => null === $row['company_name'] ? null : DbalValue::string($row['company_name']),
            'postal_code' => null === $row['postal_code'] ? null : DbalValue::string($row['postal_code']),
            'newsletter' => null !== $row['newsletter_opt_in_at'],
            'legal' => array_map(static fn (array $r): array => ['type' => DbalValue::string($r['type']), 'version' => DbalValue::int($r['version']), 'accepted_at' => new \DateTimeImmutable(DbalValue::string($r['accepted_at']))], $this->connection->fetchAllAssociative(
                'SELECT DISTINCT ON (d.type) d.type, d.version, a.accepted_at FROM identity.legal_acceptance a JOIN identity.legal_document d ON d.id = a.legal_document_id WHERE a.user_id = :id ORDER BY d.type, a.accepted_at DESC',
                ['id' => $userId],
            )),
            'cookies' => $this->connection->fetchOne('SELECT choices FROM identity.consent WHERE user_id = :id ORDER BY given_at DESC LIMIT 1', ['id' => $userId]) ?: null,
            'devices' => array_map(static fn (array $r): array => ['description' => DbalValue::string($r['description']), 'last_seen_at' => new \DateTimeImmutable(DbalValue::string($r['last_seen_at'])), 'revoked' => null !== $r['revoked_at']], $this->connection->fetchAllAssociative(
                'SELECT description, last_seen_at, revoked_at FROM identity.session_device WHERE user_id = :id ORDER BY last_seen_at DESC LIMIT 10',
                ['id' => $userId],
            )),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{id: string, name: string, email: string, phone: ?string, type: string, status: string, verified: bool, created_at: \DateTimeImmutable, last_login_at: ?\DateTimeImmutable}
     */
    private static function row(array $row): array
    {
        return [
            'id' => DbalValue::string($row['id']),
            'name' => DbalValue::string($row['first_name']).' '.DbalValue::string($row['last_name']),
            'email' => DbalValue::string($row['email']),
            'phone' => null === $row['phone'] ? null : self::maskPhone(DbalValue::string($row['phone'])),
            'type' => DbalValue::string($row['type']),
            'status' => DbalValue::string($row['status']),
            'verified' => true === $row['verified'],
            'created_at' => new \DateTimeImmutable(DbalValue::string($row['created_at'])),
            'last_login_at' => null === $row['last_login_at'] ? null : new \DateTimeImmutable(DbalValue::string($row['last_login_at'])),
        ];
    }

    /** +33612345678 → « 06 •• •• •• 78 » (deux premiers et deux derniers chiffres seulement). */
    private static function maskPhone(string $e164): string
    {
        $national = str_starts_with($e164, '+33') ? '0'.substr($e164, 3) : $e164;

        return substr($national, 0, 2).' •• •• •• '.substr($national, -2);
    }
}
