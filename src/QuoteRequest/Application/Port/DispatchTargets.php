<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Port;

use App\QuoteRequest\Application\Dispatch\DispatchTarget;

/**
 * RG-062 — artisans pouvant recevoir une demande : profil vérifié et visible, compte actif, réception non mise en
 * pause, non bloqué par le client (et jamais le client lui-même).
 */
interface DispatchTargets
{
    /** @return list<DispatchTarget> métier exercé et commune comprise dans la zone d'intervention */
    public function eligible(string $jobId, string $cityInsee, string $clientId): array;

    /** Artisan choisi par le client (demande directe), s'il peut la recevoir ; sa zone n'est pas exigée. */
    public function direct(string $artisanId, string $clientId): ?DispatchTarget;

    /**
     * Diffusion étendue (BO04) : artisans du métier dont la commune de départ est à moins de `$km` de la commune de la
     * demande, même si leur zone déclarée ne la couvre pas.
     *
     * @return list<DispatchTarget>
     */
    public function extended(string $jobId, string $cityInsee, string $clientId, int $km): array;
}
