<?php

declare(strict_types=1);

namespace App\Quote\Application\Revision;

use App\Shared\Application\Bus\Command;

/** RG-121 / P-11 — relance gratuite du client sur un devis envoyé et non consulté (une fois, après N jours). */
final readonly class RemindClient implements Command
{
    public function __construct(public string $userId, public string $requestId)
    {
    }
}
