<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Infrastructure\Agenda;

use App\ArtisanProfile\Application\Agenda\SlotReservations;
use App\ArtisanProfile\Domain\Agenda\AgendaSettings;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalSlotReservations implements SlotReservations
{
    private const string TS = 'Y-m-d\TH:i:sP';

    public function __construct(private Connection $connection)
    {
    }

    public function openSlots(string $artisanId, \DateTimeImmutable $notBefore, \DateTimeImmutable $to): array
    {
        return array_map(
            static fn (array $r): array => [new \DateTimeImmutable(DbalValue::string($r['starts_at'])), new \DateTimeImmutable(DbalValue::string($r['ends_at']))],
            $this->connection->fetchAllAssociative(
                <<<'SQL'
                    SELECT s.starts_at, s.ends_at FROM artisan.availability_slot s
                    LEFT JOIN artisan.agenda_settings g ON g.artisan_id = s.artisan_id
                    WHERE s.artisan_id = :a AND s.status = 'open' AND s.starts_at >= :from AND s.starts_at < :to AND NOT coalesce(g.paused, false)
                    ORDER BY s.starts_at
                    SQL,
                ['a' => $artisanId, 'from' => $notBefore->format(self::TS), 'to' => $to->format(self::TS)],
            ),
        );
    }

    public function noticeHours(string $artisanId): int
    {
        $hours = $this->connection->fetchOne('SELECT min_notice_hours FROM artisan.agenda_settings WHERE artisan_id = :a', ['a' => $artisanId]);

        return false === $hours ? AgendaSettings::DEFAULT_NOTICE_HOURS : DbalValue::int($hours);
    }

    public function hold(string $artisanId, \DateTimeImmutable $startsAt, string $appointmentId, \DateTimeImmutable $holdUntil): ?\DateTimeImmutable
    {
        $slot = $this->connection->fetchAssociative(
            "SELECT id, ends_at FROM artisan.availability_slot WHERE artisan_id = :a AND starts_at = :s AND status = 'open' FOR UPDATE",
            ['a' => $artisanId, 's' => $startsAt->format(self::TS)],
        );
        if (false === $slot) {
            return null;
        }
        $this->connection->executeStatement(
            "UPDATE artisan.availability_slot SET status = 'held', held_until = :h, appointment_id = :ap WHERE id = :id",
            ['h' => $holdUntil->format(self::TS), 'ap' => $appointmentId, 'id' => $slot['id']],
        );

        return new \DateTimeImmutable(DbalValue::string($slot['ends_at']));
    }

    public function book(string $appointmentId): void
    {
        $this->connection->executeStatement("UPDATE artisan.availability_slot SET status = 'booked', held_until = NULL WHERE appointment_id = :ap", ['ap' => $appointmentId]);
    }

    public function release(string $appointmentId, \DateTimeImmutable $now): void
    {
        $this->connection->executeStatement('DELETE FROM artisan.availability_slot WHERE appointment_id = :ap AND starts_at <= :now', ['ap' => $appointmentId, 'now' => $now->format(self::TS)]);
        $this->connection->executeStatement("UPDATE artisan.availability_slot SET status = 'open', held_until = NULL, appointment_id = NULL WHERE appointment_id = :ap", ['ap' => $appointmentId]);
    }
}
