<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Dispatch;

use App\Shared\Application\Bus\Command;

/**
 * RG-062 — diffuse une demande publiée ; rejouable sans double alerte (nouveaux éligibles seulement). Depuis BO04 :
 * « Re-diffuser » (`$adminId`) ou « Étendre la zone » (`$extendKm` : artisans du métier proches de la commune).
 */
final readonly class DispatchQuoteRequest implements Command
{
    public function __construct(public string $requestId, public ?string $adminId = null, public ?int $extendKm = null)
    {
    }
}
