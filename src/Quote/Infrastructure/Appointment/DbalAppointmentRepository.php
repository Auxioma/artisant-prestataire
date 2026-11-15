<?php

declare(strict_types=1);

namespace App\Quote\Infrastructure\Appointment;

use App\Quote\Domain\Appointment\Appointment;
use App\Quote\Domain\Appointment\AppointmentId;
use App\Quote\Domain\Appointment\AppointmentParty;
use App\Quote\Domain\Appointment\AppointmentRepository;
use App\Quote\Domain\Appointment\AppointmentStatus;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class DbalAppointmentRepository implements AppointmentRepository
{
    private const string TS = 'Y-m-d\TH:i:s.uP';

    public function __construct(private Connection $connection)
    {
    }

    public function findForUpdate(AppointmentId $id): ?Appointment
    {
        $r = $this->connection->fetchAssociative('SELECT * FROM quote.appointment WHERE id = :id FOR UPDATE', ['id' => $id->value]);

        return false === $r ? null : self::hydrate($r);
    }

    public function activeForQuote(string $quoteId): ?Appointment
    {
        $r = $this->connection->fetchAssociative("SELECT * FROM quote.appointment WHERE quote_id = :q AND status IN ('proposed', 'confirmed') FOR UPDATE", ['q' => $quoteId]);

        return false === $r ? null : self::hydrate($r);
    }

    public function expiredProposals(\DateTimeImmutable $now, int $limit): array
    {
        return array_map(self::hydrate(...), $this->connection->fetchAllAssociative(
            "SELECT * FROM quote.appointment WHERE status = 'proposed' AND hold_until <= :now ORDER BY hold_until LIMIT :l FOR UPDATE SKIP LOCKED",
            ['now' => $now->format(self::TS), 'l' => $limit],
            ['l' => ParameterType::INTEGER],
        ));
    }

    public function dueForReminder(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return array_map(static fn (array $r): array => ['id' => DbalValue::string($r['id']), 'quote_id' => DbalValue::string($r['quote_id'])], $this->connection->fetchAllAssociative(
            "SELECT id, quote_id FROM quote.appointment WHERE status = 'confirmed' AND reminded_at IS NULL AND starts_at >= :f AND starts_at < :t ORDER BY starts_at",
            ['f' => $from->format(self::TS), 't' => $to->format(self::TS)],
        ));
    }

    public function markReminded(string $id, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement('UPDATE quote.appointment SET reminded_at = :at WHERE id = :id', ['at' => $at->format(self::TS), 'id' => $id]);
    }

    public function save(Appointment $a): void
    {
        $f = static fn (?\DateTimeImmutable $d): ?string => $d?->format(self::TS);
        $data = [
            'status' => $a->status()->value,
            'hold_until' => $f($a->holdUntil()),
            'confirmed_at' => $f($a->confirmedAt()),
            'completed_at' => $f($a->completedAt()),
            'cancelled_at' => $f($a->cancelledAt()),
            'cancelled_by' => $a->cancelledBy()?->value,
            'cancel_reason' => $a->cancelReason(),
        ];
        if (0 === $this->connection->update('quote.appointment', $data, ['id' => $a->id()->value])) {
            $this->connection->insert('quote.appointment', $data + [
                'id' => $a->id()->value,
                'quote_id' => $a->quoteId(),
                'starts_at' => $f($a->startsAt()),
                'ends_at' => $f($a->endsAt()),
                'proposed_by' => $a->proposedBy()->value,
                'note' => $a->note(),
                'created_at' => $f($a->createdAt()),
            ]);
        }
    }

    /** @param array<string, mixed> $r */
    private static function hydrate(array $r): Appointment
    {
        $d = static fn (mixed $v): ?\DateTimeImmutable => null === $v ? null : new \DateTimeImmutable(DbalValue::string($v));

        return Appointment::restore(
            new AppointmentId(DbalValue::string($r['id'])),
            DbalValue::string($r['quote_id']),
            new \DateTimeImmutable(DbalValue::string($r['starts_at'])),
            new \DateTimeImmutable(DbalValue::string($r['ends_at'])),
            AppointmentStatus::from(DbalValue::string($r['status'])),
            AppointmentParty::from(DbalValue::string($r['proposed_by'])),
            $d($r['hold_until']),
            DbalValue::nullableString($r['note']),
            $d($r['confirmed_at']),
            $d($r['completed_at']),
            $d($r['cancelled_at']),
            null === $r['cancelled_by'] ? null : AppointmentParty::from(DbalValue::string($r['cancelled_by'])),
            DbalValue::nullableString($r['cancel_reason']),
            new \DateTimeImmutable(DbalValue::string($r['created_at'])),
        );
    }
}
