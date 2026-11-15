<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Agenda;

/**
 * Réservation des créneaux de l'agenda par les rendez-vous (module Quote, C03.6 / PR06.2). Un créneau ouvert est retenu
 * (`held`, RG-073) par une proposition, réservé (`booked`) à la confirmation, rouvert à l'annulation ou à l'expiration.
 * Les appels se font dans la transaction du rendez-vous ; le créneau est verrouillé (FOR UPDATE).
 */
interface SlotReservations
{
    /**
     * Créneaux ouverts entre deux instants (début ≥ `$notBefore`), mode pause compris : aucun si l'artisan est en pause.
     *
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>
     */
    public function openSlots(string $artisanId, \DateTimeImmutable $notBefore, \DateTimeImmutable $to): array;

    /** Délai de prévenance de l'artisan pour les propositions des clients (heures). */
    public function noticeHours(string $artisanId): int;

    /** Retient le créneau ouvert qui commence à `$startsAt` ; renvoie sa fin, ou null s'il n'est plus libre. */
    public function hold(string $artisanId, \DateTimeImmutable $startsAt, string $appointmentId, \DateTimeImmutable $holdUntil): ?\DateTimeImmutable;

    public function book(string $appointmentId): void;

    /** Rouvre le créneau du rendez-vous (supprimé s'il est déjà passé). */
    public function release(string $appointmentId, \DateTimeImmutable $now): void;
}
