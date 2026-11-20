<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Admin;

use App\Shared\Application\Admin\ScheduledExportSource;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

/** BO15.2 — export mensuel (comptabilité) des factures émises le mois précédent. */
final readonly class MonthlyInvoicesExport implements ScheduledExportSource
{
    public const string KEY = 'factures_mois';
    private const string TIMEZONE = 'Europe/Paris';

    public function __construct(private Connection $connection)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Factures du mois (comptabilité)';
    }

    public function schedule(): string
    {
        return 'Mensuel · 1er à 07:00';
    }

    public function periodLabel(\DateTimeImmutable $now): string
    {
        return $this->month($now)->format('m/Y');
    }

    public function header(): array
    {
        return ['numero', 'emise_le', 'artisan', 'statut', 'montant_ht_centimes', 'tva_centimes', 'montant_ttc_centimes', 'paye_centimes', 'payee_le'];
    }

    public function rows(\DateTimeImmutable $now): iterable
    {
        $from = $this->month($now);
        $zone = new \DateTimeZone(self::TIMEZONE);
        foreach ($this->connection->iterateAssociative(
            'SELECT i.number, i.issued_at, coalesce(b.company_name, \'\') AS company, i.status, i.amount_ht_cents, i.vat_cents, i.amount_ttc_cents, i.amount_paid_cents, i.paid_at
               FROM billing.invoice i LEFT JOIN billing.billing_profile b ON b.artisan_id = i.artisan_id
              WHERE i.issued_at >= :from AND i.issued_at < :to ORDER BY i.issued_at, i.number',
            ['from' => $from->format(\DATE_ATOM), 'to' => $from->modify('+1 month')->format(\DATE_ATOM)],
        ) as $r) {
            yield [
                DbalValue::nullableString($r['number']) ?? '', null === $r['issued_at'] ? '' : new \DateTimeImmutable(DbalValue::string($r['issued_at']))->setTimezone($zone)->format('Y-m-d'),
                DbalValue::string($r['company']), DbalValue::string($r['status']), (string) DbalValue::int($r['amount_ht_cents']), (string) DbalValue::int($r['vat_cents']),
                (string) DbalValue::int($r['amount_ttc_cents']), (string) DbalValue::int($r['amount_paid_cents']),
                null === $r['paid_at'] ? '' : new \DateTimeImmutable(DbalValue::string($r['paid_at']))->setTimezone($zone)->format('Y-m-d'),
            ];
        }
    }

    /** Premier jour du mois précédent, heure de Paris. */
    private function month(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->setTimezone(new \DateTimeZone(self::TIMEZONE))->modify('first day of previous month')->setTime(0, 0);
    }
}
