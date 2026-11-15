<?php

declare(strict_types=1);

namespace App\Quote\Application\Appointment;

interface AppointmentReader
{
    public function one(string $appointmentId): ?AppointmentView;

    /** Dernier rendez-vous du devis (actif d'abord), ou null. */
    public function forQuote(string $quoteId): ?AppointmentView;

    /**
     * Rendez-vous d'un client ou d'un artisan (selon `$party`), filtrés.
     *
     * @param 'client'|'artisan'                  $party
     * @param 'upcoming'|'past'|'cancelled'|'all' $filter
     *
     * @return list<AppointmentView>
     */
    public function forUser(string $userId, string $party, string $filter, string $query, \DateTimeImmutable $now): array;

    /**
     * PR06 « À planifier » : devis acceptés de l'artisan sans rendez-vous actif ni réalisé (demande attribuée).
     *
     * @return list<array{quote_id: string, request_id: string, request_number: string, request_title: ?string, client_name: string, city: ?string}>
     */
    public function toSchedule(string $artisanUserId): array;

    /** @return array{upcoming: int, past: int, cancelled: int, pending: int} */
    public function counts(string $userId, string $party, \DateTimeImmutable $now): array;
}
