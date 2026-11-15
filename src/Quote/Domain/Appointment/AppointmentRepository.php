<?php

declare(strict_types=1);

namespace App\Quote\Domain\Appointment;

interface AppointmentRepository
{
    public function findForUpdate(AppointmentId $id): ?Appointment;

    /** Rendez-vous proposé ou confirmé du devis (un seul à la fois), verrouillé. */
    public function activeForQuote(string $quoteId): ?Appointment;

    /** @return list<Appointment> propositions dont le verrou RG-073 est échu */
    public function expiredProposals(\DateTimeImmutable $now, int $limit): array;

    /** @return list<array{id: string, quote_id: string}> rendez-vous confirmés commençant entre deux instants, sans rappel J-1 envoyé */
    public function dueForReminder(\DateTimeImmutable $from, \DateTimeImmutable $to): array;

    public function markReminded(string $id, \DateTimeImmutable $at): void;

    public function save(Appointment $appointment): void;
}
