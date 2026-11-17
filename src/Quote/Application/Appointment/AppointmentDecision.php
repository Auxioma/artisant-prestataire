<?php

declare(strict_types=1);

namespace App\Quote\Application\Appointment;

use App\Shared\Application\Bus\Command;

/** Confirmer (l'autre partie), annuler (motif), marquer réalisé. */
final readonly class AppointmentDecision implements Command
{
    public const string CONFIRM = 'confirm';
    public const string CANCEL = 'cancel';
    public const string COMPLETE = 'complete';

    public function __construct(public string $userId, public string $appointmentId, public string $action, public ?string $reason = null)
    {
    }
}
