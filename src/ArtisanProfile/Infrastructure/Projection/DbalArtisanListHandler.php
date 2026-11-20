<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Infrastructure\Projection;

use App\ArtisanProfile\Application\Admin\ArtisanList;
use App\ArtisanProfile\Application\Admin\ArtisanListPage;
use App\Shared\Application\Billing\RemainingCredits;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

/** BO03.1 — liste paginée (requêtes paramétrées) ; métiers lus dans la projection `artisan_job`, crédits auprès de Billing. */
final readonly class DbalArtisanListHandler implements QueryHandler
{
    private const int MAX_PER_PAGE = 100;
    private const array STATUSES = ['incomplete', 'pending_verification', 'verified', 'rejected', 'suspended'];

    public function __construct(private Connection $connection, private QueryBus $queries)
    {
    }

    public function __invoke(ArtisanList $query): ArtisanListPage
    {
        $where = ['true'];
        $params = [];
        $term = trim($query->search);
        if ('' !== $term) {
            $where[] = '(lower(coalesce(p.company_name, \'\') || \' \' || coalesce(p.trade_name, \'\')) LIKE :term OR p.siret LIKE :digits)';
            $params['term'] = '%'.mb_strtolower(str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term)).'%';
            $digits = (string) preg_replace('/\D/', '', $term);
            $params['digits'] = '' === $digits ? '-' : $digits.'%';
        }
        if ('hidden' === $query->status) {
            $where[] = 'p.hidden_at IS NOT NULL';
        } elseif ('documents' === $query->status) {
            // BO01.2 « Justificatifs à contrôler » : au moins une pièce déposée en attente de contrôle.
            $where[] = "EXISTS (SELECT 1 FROM artisan.document d WHERE d.artisan_id = p.id AND d.status = 'pending_review')";
        } elseif (\in_array($query->status, self::STATUSES, true)) {
            $where[] = 'p.status = :status';
            $params['status'] = $query->status;
        }
        $conditions = implode(' AND ', $where);
        $perPage = max(1, min($query->perPage, self::MAX_PER_PAGE));
        $page = max(1, $query->page);
        $total = DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM artisan.artisan_profile p WHERE '.$conditions, $params));
        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT p.id, p.user_id, coalesce(p.trade_name, p.company_name, '—') AS name, p.siret, p.hq_postal_code, p.status,
                       p.hidden_at IS NOT NULL AS hidden, p.completion_rate, p.created_at,
                       coalesce((SELECT string_agg(j.name, ', ' ORDER BY aj.is_main DESC, j.name) FROM artisan.artisan_job aj JOIN catalog.job j ON j.id = aj.job_id WHERE aj.artisan_id = p.id), '') AS jobs
                FROM artisan.artisan_profile p WHERE {$conditions}
                ORDER BY p.created_at DESC LIMIT :limit OFFSET :offset
                SQL,
            $params + ['limit' => $perPage, 'offset' => ($page - 1) * $perPage],
        );
        $credits = [] === $rows ? [] : $this->queries->ask(new RemainingCredits(array_map(static fn (array $r): string => DbalValue::string($r['user_id']), $rows)));
        $credits = \is_array($credits) ? $credits : [];

        return new ArtisanListPage(array_map(static function (array $r) use ($credits): array {
            $userId = DbalValue::string($r['user_id']);

            return [
                'id' => DbalValue::string($r['id']),
                'user_id' => $userId,
                'name' => DbalValue::string($r['name']),
                'siret' => null === $r['siret'] ? null : DbalValue::string($r['siret']),
                'jobs' => DbalValue::string($r['jobs']),
                'department' => null === $r['hq_postal_code'] ? null : substr(DbalValue::string($r['hq_postal_code']), 0, 2),
                'status' => DbalValue::string($r['status']),
                'hidden' => true === $r['hidden'],
                'completion' => DbalValue::int($r['completion_rate']),
                'created_at' => new \DateTimeImmutable(DbalValue::string($r['created_at'])),
                'credits' => isset($credits[$userId]) && \is_int($credits[$userId]) ? $credits[$userId] : null,
            ];
        }, $rows), $total, $page, $perPage);
    }
}
