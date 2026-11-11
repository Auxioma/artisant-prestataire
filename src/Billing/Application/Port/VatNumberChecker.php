<?php

declare(strict_types=1);

namespace App\Billing\Application\Port;

use App\Shared\Application\Integration\ExternalServiceUnavailable;

/** Numéro de TVA intracommunautaire (ADR-0009 : autoliquidation UE) — service VIES de la Commission européenne. */
interface VatNumberChecker
{
    /**
     * @param string $vatNumber numéro complet avec préfixe pays (« FR40303265045 », espaces tolérés)
     *
     * @throws ExternalServiceUnavailable registre national ou VIES indisponible (fréquent : réessayer plus tard)
     */
    public function check(string $vatNumber): VatNumberCheck;
}
