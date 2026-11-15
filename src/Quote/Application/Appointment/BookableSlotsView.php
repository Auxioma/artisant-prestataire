<?php

declare(strict_types=1);

namespace App\Quote\Application\Appointment;

final readonly class BookableSlotsView
{
    /**
     * @param array<string, list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>> $days date locale Y-m-d => créneaux
     */
    public function __construct(
        public string $party,
        public string $quoteId,
        public string $quoteNumber,
        public string $requestId,
        public string $requestNumber,
        public ?string $requestTitle,
        public string $artisanName,
        public string $clientFirstName,
        public array $days,
        public int $noticeHours,
        public ?AppointmentView $current,
    ) {
    }
}
