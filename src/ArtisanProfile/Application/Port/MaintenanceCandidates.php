<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Port;

/** Sélections des tâches planifiées (requêtes indexées, sans charger tous les profils). */
interface MaintenanceCandidates
{
    /**
     * Pièces validées ou à contrôler dont l'échéance est au plus tard `$horizon`.
     *
     * @return list<string> identifiants de pièce
     */
    public function documentsExpiringBy(\DateTimeImmutable $horizon): array;

    /**
     * CDC §11 — pièces encore conservées d'artisans dont la relation a pris fin avant `$before` : compte effacé
     * (anonymisé) ou dossier refusé.
     *
     * @return list<string> identifiants de pièce
     */
    public function documentsOfRelationsEndedBefore(\DateTimeImmutable $before): array;

    /**
     * Profils vérifiés, non masqués, déclarant une assurance échue avant `$before`.
     *
     * @return list<string>
     */
    public function verifiedProfilesWithInsuranceEndingBefore(\DateTimeImmutable $before): array;

    /**
     * Profils incomplets ou en attente de vérification (relances E-23).
     *
     * @return list<string>
     */
    public function unfinishedDossiers(): array;
}
