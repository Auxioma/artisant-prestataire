<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Port;

/** RG-063 — score anti-spam d'une demande (mots-clés, liens, coordonnées, doublons, vitesse) ; calcul livré par T-403. */
interface RequestRiskAssessor
{
    public function assess(RequestRiskInput $input): RequestRisk;
}
