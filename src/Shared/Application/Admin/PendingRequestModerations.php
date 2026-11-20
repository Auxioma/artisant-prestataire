<?php

declare(strict_types=1);

namespace App\Shared\Application\Admin;

use App\Shared\Application\Bus\Query;

/** BO01.2 / BO04.1 — demandes en attente de modération (RG-063 ; traitée par QuoteRequest). Réponse : int. */
final readonly class PendingRequestModerations implements Query
{
}
