<?php

declare(strict_types=1);

namespace App\Quote\Domain\Appointment;

use App\Quote\Domain\Appointment\Event\AppointmentChanged;
use App\Shared\Domain\AggregateRoot;

/**
 * Rendez-vous d'un devis accepté (C03.6, PR06.2) sur un créneau de l'agenda de l'artisan. Proposé par une partie, il est
 * confirmé par l'autre ; une proposition non confirmée expire à `holdUntil` (RG-073). Annulation motivée, report
 * (nouvelle proposition), réalisation après l'heure de début.
 */
final class Appointment extends AggregateRoot
{
    public const int NOTE_MAX = 500;
    public const int REASON_MAX = 300;

    private function __construct(
        private readonly AppointmentId $id,
        private readonly string $quoteId,
        private readonly \DateTimeImmutable $startsAt,
        private readonly \DateTimeImmutable $endsAt,
        private AppointmentStatus $status,
        private readonly AppointmentParty $proposedBy,
        private ?\DateTimeImmutable $holdUntil,
        private readonly ?string $note,
        private ?\DateTimeImmutable $confirmedAt,
        private ?\DateTimeImmutable $completedAt,
        private ?\DateTimeImmutable $cancelledAt,
        private ?AppointmentParty $cancelledBy,
        private ?string $cancelReason,
        private readonly \DateTimeImmutable $createdAt,
    ) {
    }

    public static function propose(AppointmentId $id, string $quoteId, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt, AppointmentParty $by, \DateTimeImmutable $holdUntil, ?string $note, \DateTimeImmutable $now): self
    {
        if ($startsAt <= $now) {
            throw new AppointmentRuleViolation('Ce créneau est déjà passé.');
        }
        $note = null === $note ? null : trim($note);
        if (null !== $note && mb_strlen($note) > self::NOTE_MAX) {
            throw new AppointmentRuleViolation(\sprintf('Note limitée à %d caractères.', self::NOTE_MAX));
        }
        $appointment = new self($id, $quoteId, $startsAt, $endsAt, AppointmentStatus::Proposed, $by, min($holdUntil, $startsAt), '' === $note ? null : $note, null, null, null, null, null, $now);
        $appointment->record('proposed', $by, null, $now);

        return $appointment;
    }

    /** @internal reconstitution depuis la persistance */
    public static function restore(AppointmentId $id, string $quoteId, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt, AppointmentStatus $status, AppointmentParty $proposedBy, ?\DateTimeImmutable $holdUntil, ?string $note, ?\DateTimeImmutable $confirmedAt, ?\DateTimeImmutable $completedAt, ?\DateTimeImmutable $cancelledAt, ?AppointmentParty $cancelledBy, ?string $cancelReason, \DateTimeImmutable $createdAt): self
    {
        return new self($id, $quoteId, $startsAt, $endsAt, $status, $proposedBy, $holdUntil, $note, $confirmedAt, $completedAt, $cancelledAt, $cancelledBy, $cancelReason, $createdAt);
    }

    /** Seule l'autre partie confirme une proposition, avant son expiration. */
    public function confirm(AppointmentParty $by, \DateTimeImmutable $now): void
    {
        if (AppointmentStatus::Proposed !== $this->status) {
            throw new AppointmentRuleViolation('Ce rendez-vous n’est plus en attente de confirmation.');
        }
        if ($by === $this->proposedBy) {
            throw new AppointmentRuleViolation('Votre proposition attend la confirmation de l’autre partie.');
        }
        if (null !== $this->holdUntil && $now >= $this->holdUntil) {
            throw new AppointmentRuleViolation('Cette proposition a expiré : le créneau a été libéré.');
        }
        $this->status = AppointmentStatus::Confirmed;
        $this->confirmedAt = $now;
        $this->holdUntil = null;
        $this->record('confirmed', $by, null, $now);
    }

    public function cancel(AppointmentParty $by, string $reason, \DateTimeImmutable $now): void
    {
        $reason = trim($reason);
        if ('' === $reason) {
            throw new AppointmentRuleViolation('Indiquez le motif de l’annulation.');
        }
        if (mb_strlen($reason) > self::REASON_MAX) {
            throw new AppointmentRuleViolation(\sprintf('Motif limité à %d caractères.', self::REASON_MAX));
        }
        $this->close(AppointmentStatus::Cancelled, $by, $reason, $now);
        $this->record('cancelled', $by, $reason, $now);
    }

    /** Report : ce rendez-vous est remplacé par une nouvelle proposition. */
    public function reschedule(AppointmentParty $by, \DateTimeImmutable $now): void
    {
        $this->close(AppointmentStatus::Rescheduled, $by, null, $now);
    }

    /** RG-073 : proposition non confirmée à temps. */
    public function expire(\DateTimeImmutable $now): void
    {
        if (AppointmentStatus::Proposed !== $this->status || null === $this->holdUntil || $now < $this->holdUntil) {
            return;
        }
        $this->status = AppointmentStatus::Expired;
        $this->holdUntil = null;
        $this->record('expired', null, null, $now);
    }

    /** Prestation réalisée : « Marquer réalisé » (artisan) ou « La prestation a-t-elle eu lieu ? » (client). */
    public function complete(AppointmentParty $by, \DateTimeImmutable $now): void
    {
        if (AppointmentStatus::Confirmed !== $this->status) {
            throw new AppointmentRuleViolation('Seul un rendez-vous confirmé peut être marqué réalisé.');
        }
        if ($now < $this->startsAt) {
            throw new AppointmentRuleViolation('Le rendez-vous n’a pas encore eu lieu.');
        }
        $this->status = AppointmentStatus::Completed;
        $this->completedAt = $now;
        $this->record('completed', $by, null, $now);
    }

    private function close(AppointmentStatus $status, AppointmentParty $by, ?string $reason, \DateTimeImmutable $now): void
    {
        if (!$this->status->isActive()) {
            throw new AppointmentRuleViolation('Ce rendez-vous est déjà clos.');
        }
        if ($now >= $this->startsAt) {
            throw new AppointmentRuleViolation('Le rendez-vous a commencé : il ne peut plus être annulé ni reporté.');
        }
        $this->status = $status;
        $this->cancelledAt = $now;
        $this->cancelledBy = $by;
        $this->cancelReason = $reason;
        $this->holdUntil = null;
    }

    private function record(string $change, ?AppointmentParty $actor, ?string $reason, \DateTimeImmutable $now): void
    {
        $this->recordEvent(new AppointmentChanged($this->id->value, $this->quoteId, $change, $actor?->value, $reason, $now));
    }

    public function id(): AppointmentId
    {
        return $this->id;
    }

    public function quoteId(): string
    {
        return $this->quoteId;
    }

    public function startsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function endsAt(): \DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function status(): AppointmentStatus
    {
        return $this->status;
    }

    public function proposedBy(): AppointmentParty
    {
        return $this->proposedBy;
    }

    public function holdUntil(): ?\DateTimeImmutable
    {
        return $this->holdUntil;
    }

    public function note(): ?string
    {
        return $this->note;
    }

    public function confirmedAt(): ?\DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function completedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function cancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function cancelledBy(): ?AppointmentParty
    {
        return $this->cancelledBy;
    }

    public function cancelReason(): ?string
    {
        return $this->cancelReason;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
