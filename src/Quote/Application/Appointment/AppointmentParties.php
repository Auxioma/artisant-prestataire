<?php

declare(strict_types=1);

namespace App\Quote\Application\Appointment;

/** Devis, demande et parties d'un rendez-vous (lecture, sans verrou). */
interface AppointmentParties
{
    /**
     * @return array{quote_id: string, quote_number: string, quote_status: string, request_id: string, request_number: string, request_title: ?string,
     *     artisan_id: string, artisan_user_id: string, artisan_name: string, artisan_email: ?string, artisan_first_name: ?string,
     *     client_user_id: string, client_email: ?string, client_first_name: ?string}|null
     */
    public function ofQuote(string $quoteId): ?array;
}
