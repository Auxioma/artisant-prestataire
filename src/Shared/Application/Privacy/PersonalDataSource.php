<?php

declare(strict_types=1);

namespace App\Shared\Application\Privacy;

/**
 * Droit d'accès et à la portabilité (RGPD art. 15 et 20, C07.4) : chaque module livre les données personnelles qu'il
 * détient sur un compte. Valeurs scalaires, listes ou tableaux uniquement (sérialisées en JSON et rendues en PDF).
 */
interface PersonalDataSource
{
    public const string TAG = 'app.personal_data_source';

    /** Clé stable de la section dans l'archive JSON. */
    public function key(): string;

    /** Titre lisible (PDF). */
    public function title(): string;

    /** @return list<array<string, scalar|array<mixed>|null>> lignes de la section (vide : section omise) */
    public function collect(string $userId): array;
}
