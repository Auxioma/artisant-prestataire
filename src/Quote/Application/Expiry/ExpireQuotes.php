<?php

declare(strict_types=1);

namespace App\Quote\Application\Expiry;

use App\Shared\Application\Bus\Command;

/** RG-121 — devis envoyés ou consultés dont la validité est dépassée : « expiré », place libérée sur la demande. */
final readonly class ExpireQuotes implements Command
{
    public const int BATCH = 200;
}
