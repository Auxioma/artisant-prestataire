<?php

declare(strict_types=1);

namespace App\Quote\Infrastructure\Appointment;

use App\Quote\Application\Appointment\AppointmentParties;
use App\Quote\Application\Appointment\AppointmentReader;
use App\Quote\Application\Appointment\AppointmentView;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

/** Rendez-vous et parties ; côté artisan, le client n'est désigné que par son prénom (RG-112). */
final readonly class DbalAppointmentReader implements AppointmentReader, AppointmentParties
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const string TS = 'Y-m-d\TH:i:sP';
    private const string PARTIES = <<<'SQL'
        SELECT q.id AS quote_id, coalesce(q.number, '') AS quote_number, q.status AS quote_status, r.id AS request_id, r.number AS request_number, r.title AS request_title,
               cm.name AS city, p.id AS artisan_id, p.user_id AS artisan_user_id, coalesce(p.trade_name, p.company_name, 'Professionnel') AS artisan_name,
               ua.email AS artisan_email, ua.first_name AS artisan_first_name, r.client_id AS client_user_id, uc.email AS client_email, uc.first_name AS client_first_name
        FROM quote.quote q
        JOIN request.quote_request r ON r.id = q.request_id
        JOIN artisan.artisan_profile p ON p.id = q.artisan_id
        LEFT JOIN identity.user_account ua ON ua.id = p.user_id AND ua.status <> 'anonymized'
        LEFT JOIN identity.user_account uc ON uc.id = r.client_id AND uc.status <> 'anonymized'
        LEFT JOIN catalog.commune cm ON cm.insee = r.city_insee
        SQL;

    public function __construct(private Connection $connection)
    {
    }

    public function ofQuote(string $quoteId): ?array
    {
        if (1 !== preg_match(self::UUID, $quoteId)) {
            return null;
        }
        $r = $this->connection->fetchAssociative(self::PARTIES.' WHERE q.id = :q', ['q' => $quoteId]);
        if (false === $r) {
            return null;
        }

        return [
            'quote_id' => DbalValue::string($r['quote_id']), 'quote_number' => DbalValue::string($r['quote_number']), 'quote_status' => DbalValue::string($r['quote_status']),
            'request_id' => DbalValue::string($r['request_id']), 'request_number' => DbalValue::string($r['request_number']), 'request_title' => DbalValue::nullableString($r['request_title']),
            'artisan_id' => DbalValue::string($r['artisan_id']), 'artisan_user_id' => DbalValue::string($r['artisan_user_id']), 'artisan_name' => DbalValue::string($r['artisan_name']),
            'artisan_email' => DbalValue::nullableString($r['artisan_email']), 'artisan_first_name' => DbalValue::nullableString($r['artisan_first_name']),
            'client_user_id' => DbalValue::string($r['client_user_id']), 'client_email' => DbalValue::nullableString($r['client_email']), 'client_first_name' => DbalValue::nullableString($r['client_first_name']),
        ];
    }

    public function one(string $appointmentId): ?AppointmentView
    {
        if (1 !== preg_match(self::UUID, $appointmentId)) {
            return null;
        }
        $r = $this->connection->fetchAssociative('SELECT a.*, x.* FROM quote.appointment a JOIN ('.self::PARTIES.') x ON x.quote_id = a.quote_id WHERE a.id = :id', ['id' => $appointmentId]);

        return false === $r ? null : self::view($r);
    }

    public function forQuote(string $quoteId): ?AppointmentView
    {
        if (1 !== preg_match(self::UUID, $quoteId)) {
            return null;
        }
        $r = $this->connection->fetchAssociative(
            'SELECT a.*, x.* FROM quote.appointment a JOIN ('.self::PARTIES.") x ON x.quote_id = a.quote_id WHERE a.quote_id = :q ORDER BY (a.status IN ('proposed', 'confirmed')) DESC, a.created_at DESC LIMIT 1",
            ['q' => $quoteId],
        );

        return false === $r ? null : self::view($r);
    }

    public function forUser(string $userId, string $party, string $filter, string $query, \DateTimeImmutable $now): array
    {
        if (1 !== preg_match(self::UUID, $userId)) {
            return [];
        }
        $who = 'artisan' === $party ? 'x.artisan_user_id' : 'x.client_user_id';
        $where = match ($filter) {
            'upcoming' => "a.status IN ('proposed', 'confirmed') AND a.ends_at > :now",
            'past' => "(a.status = 'completed' OR (a.status = 'confirmed' AND a.ends_at <= :now))",
            'cancelled' => "a.status IN ('cancelled', 'expired')",
            default => "a.status <> 'rescheduled'",
        };
        $params = ['u' => $userId, 'now' => $now->format(self::TS)];
        if ('' !== $query) {
            $where .= " AND (x.artisan_name ILIKE :q ESCAPE '\\' OR x.request_title ILIKE :q ESCAPE '\\' OR x.request_number ILIKE :q ESCAPE '\\' OR x.client_first_name ILIKE :q ESCAPE '\\')";
            $params['q'] = '%'.addcslashes($query, '%_\\').'%';
        }
        $order = 'past' === $filter || 'cancelled' === $filter ? 'a.starts_at DESC' : 'a.starts_at';

        return array_map(self::view(...), $this->connection->fetchAllAssociative(
            'SELECT a.*, x.* FROM quote.appointment a JOIN ('.self::PARTIES.') x ON x.quote_id = a.quote_id WHERE '.$who.' = :u AND '.$where.' ORDER BY '.$order,
            $params,
        ));
    }

    public function counts(string $userId, string $party, \DateTimeImmutable $now): array
    {
        $zero = ['upcoming' => 0, 'past' => 0, 'cancelled' => 0, 'pending' => 0];
        if (1 !== preg_match(self::UUID, $userId)) {
            return $zero;
        }
        $who = 'artisan' === $party ? 'x.artisan_user_id' : 'x.client_user_id';
        $r = $this->connection->fetchAssociative(
            "SELECT count(*) FILTER (WHERE a.status IN ('proposed', 'confirmed') AND a.ends_at > :now) AS upcoming,
                    count(*) FILTER (WHERE a.status = 'completed' OR (a.status = 'confirmed' AND a.ends_at <= :now)) AS past,
                    count(*) FILTER (WHERE a.status IN ('cancelled', 'expired')) AS cancelled,
                    count(*) FILTER (WHERE a.status = 'proposed' AND a.proposed_by <> :party) AS pending
             FROM quote.appointment a JOIN (".self::PARTIES.') x ON x.quote_id = a.quote_id WHERE '.$who.' = :u',
            ['u' => $userId, 'now' => $now->format(self::TS), 'party' => $party],
        );

        return false === $r ? $zero : array_map(static fn (mixed $v): int => DbalValue::int($v), array_intersect_key($r, $zero)) + $zero;
    }

    /** @param array<string, mixed> $r */
    private static function view(array $r): AppointmentView
    {
        $d = static fn (mixed $v): ?\DateTimeImmutable => null === $v ? null : new \DateTimeImmutable(DbalValue::string($v));

        return new AppointmentView(
            DbalValue::string($r['id']),
            DbalValue::string($r['quote_id']),
            DbalValue::string($r['quote_number']),
            DbalValue::string($r['request_id']),
            DbalValue::string($r['request_number']),
            DbalValue::nullableString($r['request_title']),
            DbalValue::nullableString($r['city']),
            DbalValue::string($r['artisan_id']),
            DbalValue::string($r['artisan_user_id']),
            DbalValue::string($r['artisan_name']),
            DbalValue::nullableString($r['artisan_email']),
            DbalValue::nullableString($r['artisan_first_name']),
            DbalValue::string($r['client_user_id']),
            DbalValue::nullableString($r['client_email']),
            DbalValue::nullableString($r['client_first_name']),
            new \DateTimeImmutable(DbalValue::string($r['starts_at'])),
            new \DateTimeImmutable(DbalValue::string($r['ends_at'])),
            DbalValue::string($r['status']),
            DbalValue::string($r['proposed_by']),
            $d($r['hold_until']),
            DbalValue::nullableString($r['note']),
            DbalValue::nullableString($r['cancelled_by']),
            DbalValue::nullableString($r['cancel_reason']),
        );
    }
}
