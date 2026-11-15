<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Infrastructure\Agenda;

use App\ArtisanProfile\Application\Port\AgendaStore;
use App\ArtisanProfile\Domain\Agenda\Absence;
use App\ArtisanProfile\Domain\Agenda\AbsenceKind;
use App\ArtisanProfile\Domain\Agenda\AgendaSettings;
use App\ArtisanProfile\Domain\Agenda\WeeklyRule;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalAgendaStore implements AgendaStore
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const string TS = 'Y-m-d\TH:i:sP';

    public function __construct(private Connection $connection)
    {
    }

    public function artisanOfUser(string $userId): ?string
    {
        if (1 !== preg_match(self::UUID, $userId)) {
            return null;
        }
        $id = $this->connection->fetchOne('SELECT id FROM artisan.artisan_profile WHERE user_id = :u', ['u' => $userId]);

        return false === $id ? null : DbalValue::string($id);
    }

    public function settings(string $artisanId): AgendaSettings
    {
        $r = $this->connection->fetchAssociative('SELECT slot_minutes, min_notice_hours, closed_on_holidays, paused FROM artisan.agenda_settings WHERE artisan_id = :a', ['a' => $artisanId]);

        return false === $r ? new AgendaSettings() : new AgendaSettings(DbalValue::int($r['slot_minutes']), DbalValue::int($r['min_notice_hours']), true === $r['closed_on_holidays'], true === $r['paused']);
    }

    public function saveSettings(string $artisanId, AgendaSettings $settings, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO artisan.agenda_settings (artisan_id, slot_minutes, min_notice_hours, closed_on_holidays, paused, updated_at)
                VALUES (:a, :slot, :notice, :holidays, :paused, :at)
                ON CONFLICT (artisan_id) DO UPDATE SET slot_minutes = excluded.slot_minutes, min_notice_hours = excluded.min_notice_hours,
                    closed_on_holidays = excluded.closed_on_holidays, paused = excluded.paused, updated_at = excluded.updated_at
                SQL,
            ['a' => $artisanId, 'slot' => $settings->slotMinutes, 'notice' => $settings->minNoticeHours, 'holidays' => $settings->closedOnHolidays ? 'true' : 'false', 'paused' => $settings->paused ? 'true' : 'false', 'at' => $at->format(self::TS)],
        );
    }

    public function rules(string $artisanId): array
    {
        return array_map(
            static fn (array $r): WeeklyRule => new WeeklyRule(DbalValue::int($r['weekday']), DbalValue::int($r['start_minute']), DbalValue::int($r['end_minute'])),
            $this->connection->fetchAllAssociative('SELECT weekday, start_minute, end_minute FROM artisan.availability_rule WHERE artisan_id = :a ORDER BY weekday, start_minute', ['a' => $artisanId]),
        );
    }

    public function replaceRules(string $artisanId, array $rules, array $ids): void
    {
        $this->connection->executeStatement('DELETE FROM artisan.availability_rule WHERE artisan_id = :a', ['a' => $artisanId]);
        foreach ($rules as $i => $rule) {
            $this->connection->insert('artisan.availability_rule', ['id' => $ids[$i], 'artisan_id' => $artisanId, 'weekday' => $rule->weekday, 'start_minute' => $rule->start, 'end_minute' => $rule->end]);
        }
    }

    public function absences(string $artisanId, ?\DateTimeImmutable $since = null): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, starts_on, ends_on, kind, label FROM artisan.availability_absence WHERE artisan_id = :a AND ends_on >= coalesce(CAST(:since AS date), ends_on) ORDER BY starts_on',
            ['a' => $artisanId, 'since' => $since?->format('Y-m-d')],
        );

        return array_map(static fn (array $r): Absence => new Absence(
            DbalValue::string($r['id']),
            new \DateTimeImmutable(DbalValue::string($r['starts_on'])),
            new \DateTimeImmutable(DbalValue::string($r['ends_on'])),
            AbsenceKind::from(DbalValue::string($r['kind'])),
            DbalValue::nullableString($r['label']),
        ), $rows);
    }

    public function addAbsence(string $artisanId, Absence $absence, \DateTimeImmutable $at): bool
    {
        $overlap = $this->connection->fetchOne(
            'SELECT 1 FROM artisan.availability_absence WHERE artisan_id = :a AND starts_on <= :to AND ends_on >= :from',
            ['a' => $artisanId, 'from' => $absence->from->format('Y-m-d'), 'to' => $absence->to->format('Y-m-d')],
        );
        if (false !== $overlap) {
            return false;
        }
        $this->connection->insert('artisan.availability_absence', [
            'id' => $absence->id,
            'artisan_id' => $artisanId,
            'starts_on' => $absence->from->format('Y-m-d'),
            'ends_on' => $absence->to->format('Y-m-d'),
            'kind' => $absence->kind->value,
            'label' => $absence->label,
            'created_at' => $at->format(self::TS),
        ]);

        return true;
    }

    public function removeAbsence(string $artisanId, string $absenceId): bool
    {
        if (1 !== preg_match(self::UUID, $absenceId)) {
            return false;
        }

        return 1 === (int) $this->connection->executeStatement('DELETE FROM artisan.availability_absence WHERE id = :id AND artisan_id = :a', ['id' => $absenceId, 'a' => $artisanId]);
    }

    public function busy(string $artisanId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return array_map(
            static fn (array $r): array => [new \DateTimeImmutable(DbalValue::string($r['starts_at'])), new \DateTimeImmutable(DbalValue::string($r['ends_at']))],
            $this->connection->fetchAllAssociative(
                "SELECT starts_at, ends_at FROM artisan.availability_slot WHERE artisan_id = :a AND status <> 'open' AND ends_at > :from AND starts_at < :to",
                ['a' => $artisanId, 'from' => $from->format(self::TS), 'to' => $to->format(self::TS)],
            ),
        );
    }

    public function replaceOpenSlots(string $artisanId, \DateTimeImmutable $from, array $slots): void
    {
        $this->connection->executeStatement("DELETE FROM artisan.availability_slot WHERE artisan_id = :a AND status = 'open' AND starts_at >= :from", ['a' => $artisanId, 'from' => $from->format(self::TS)]);
        foreach (array_chunk($slots, 200) as $chunk) {
            $values = [];
            $params = ['a' => $artisanId];
            foreach ($chunk as $i => [$id, $start, $end]) {
                $values[] = "(:id{$i}, :a, :s{$i}, :e{$i})";
                $params["id{$i}"] = $id;
                $params["s{$i}"] = $start->format(self::TS);
                $params["e{$i}"] = $end->format(self::TS);
            }
            $this->connection->executeStatement('INSERT INTO artisan.availability_slot (id, artisan_id, starts_at, ends_at) VALUES '.implode(', ', $values), $params);
        }
    }

    public function slots(string $artisanId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return array_map(
            static fn (array $r): array => [new \DateTimeImmutable(DbalValue::string($r['starts_at'])), new \DateTimeImmutable(DbalValue::string($r['ends_at'])), DbalValue::string($r['status'])],
            $this->connection->fetchAllAssociative(
                'SELECT starts_at, ends_at, status FROM artisan.availability_slot WHERE artisan_id = :a AND ends_at > :from AND starts_at < :to ORDER BY starts_at',
                ['a' => $artisanId, 'from' => $from->format(self::TS), 'to' => $to->format(self::TS)],
            ),
        );
    }

    public function availableSoon(string $artisanId, int $days): bool
    {
        if (1 !== preg_match(self::UUID, $artisanId)) {
            return false;
        }

        return true === $this->connection->fetchOne(
            <<<'SQL'
                SELECT NOT coalesce(g.paused, false) AND EXISTS (
                    SELECT 1 FROM artisan.availability_slot s WHERE s.artisan_id = p.id AND s.status = 'open'
                      AND s.starts_at >= now() + make_interval(hours => coalesce(g.min_notice_hours, :notice))
                      AND s.starts_at < now() + make_interval(days => :days))
                FROM artisan.artisan_profile p LEFT JOIN artisan.agenda_settings g ON g.artisan_id = p.id WHERE p.id = :a
                SQL,
            ['a' => $artisanId, 'notice' => AgendaSettings::DEFAULT_NOTICE_HOURS, 'days' => $days],
        );
    }

    public function saveIcalToken(string $artisanId, ?string $hash, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO artisan.agenda_settings (artisan_id, ical_token_hash, ical_token_created_at, updated_at) VALUES (:a, :h, CAST(:c AS timestamptz), :at)
                ON CONFLICT (artisan_id) DO UPDATE SET ical_token_hash = excluded.ical_token_hash, ical_token_created_at = excluded.ical_token_created_at, updated_at = excluded.updated_at
                SQL,
            ['a' => $artisanId, 'h' => $hash, 'c' => null === $hash ? null : $at->format(self::TS), 'at' => $at->format(self::TS)],
        );
    }

    public function icalTokenCreatedAt(string $artisanId): ?\DateTimeImmutable
    {
        $at = $this->connection->fetchOne('SELECT ical_token_created_at FROM artisan.agenda_settings WHERE artisan_id = :a', ['a' => $artisanId]);

        return \is_string($at) ? new \DateTimeImmutable($at) : null;
    }

    public function artisanByIcalHash(string $hash): ?array
    {
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $hash)) {
            return null;
        }
        $r = $this->connection->fetchAssociative(
            'SELECT p.id, coalesce(p.trade_name, p.company_name, \'TrouveMoi\') AS name FROM artisan.agenda_settings g JOIN artisan.artisan_profile p ON p.id = g.artisan_id WHERE g.ical_token_hash = :h',
            ['h' => $hash],
        );

        return false === $r ? null : ['artisan_id' => DbalValue::string($r['id']), 'name' => DbalValue::string($r['name'])];
    }

    public function artisansWithRules(): array
    {
        return array_map(static fn (mixed $id): string => DbalValue::string($id), $this->connection->fetchFirstColumn('SELECT DISTINCT artisan_id FROM artisan.availability_rule ORDER BY artisan_id'));
    }

    public function purgeOpenSlotsBefore(\DateTimeImmutable $before): int
    {
        return (int) $this->connection->executeStatement("DELETE FROM artisan.availability_slot WHERE status = 'open' AND ends_at < :b", ['b' => $before->format(self::TS)]);
    }
}
