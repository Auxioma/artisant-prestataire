<?php

declare(strict_types=1);

namespace App\QuoteRequest\Infrastructure\Risk;

use App\QuoteRequest\Application\Port\RequestRisk;
use App\QuoteRequest\Application\Port\RequestRiskAssessor;
use App\QuoteRequest\Application\Port\RequestRiskInput;

/** Provisoire (T-401) : aucun signal, score nul — remplacé par le calcul RG-063 et la file de modération en T-403. */
final readonly class InterimRequestRiskAssessor implements RequestRiskAssessor
{
    public function assess(RequestRiskInput $input): RequestRisk
    {
        return new RequestRisk(0, []);
    }
}
