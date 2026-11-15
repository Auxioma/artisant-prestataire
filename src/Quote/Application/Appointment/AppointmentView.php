<?php

declare(strict_types=1);

namespace App\Quote\Application\Appointment;

/** Rendez-vous lu avec ses parties (écrans C03B, C10, PR06 ; notifications). */
final readonly class AppointmentView
{
    public function __construct(
        public string $id,
        public string $quoteId,
        public string $quoteNumber,
        public string $requestId,
        public string $requestNumber,
        public ?string $requestTitle,
        public ?string $city,
        public string $artisanId,
        public string $artisanUserId,
        public string $artisanName,
        public ?string $artisanEmail,
        public ?string $artisanFirstName,
        public string $clientUserId,
        public ?string $clientEmail,
        public ?string $clientFirstName,
        public \DateTimeImmutable $startsAt,
        public \DateTimeImmutable $endsAt,
        public string $status,
        public string $proposedBy,
        public ?\DateTimeImmutable $holdUntil,
        public ?string $note,
        public ?string $cancelledBy,
        public ?string $cancelReason,
        public ?string $clientLastName = null,
    ) {
    }

    /** Nom du client (prénom et nom : coordonnées révélées à l'acceptation du devis, RG-022bis). */
    public function clientName(): string
    {
        return trim(($this->clientFirstName ?? 'Client').' '.($this->clientLastName ?? ''));
    }

    public function statusLabel(): string
    {
        return \App\Quote\Domain\Appointment\AppointmentStatus::from($this->status)->label();
    }

    public function isActive(): bool
    {
        return \in_array($this->status, ['proposed', 'confirmed'], true);
    }

    /** La partie qui doit confirmer (l'autre que celle qui a proposé), tant que c'est en attente. */
    public function awaits(string $party): bool
    {
        return 'proposed' === $this->status && $party !== $this->proposedBy;
    }
}
