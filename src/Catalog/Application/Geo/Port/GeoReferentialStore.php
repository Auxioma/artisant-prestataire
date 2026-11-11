<?php

declare(strict_types=1);

namespace App\Catalog\Application\Geo\Port;

use App\Catalog\Application\Geo\CommuneRow;
use App\Catalog\Application\Geo\DepartmentRecord;
use App\Catalog\Application\Geo\RegionRecord;

interface GeoReferentialStore
{
    /** @param list<RegionRecord> $regions */
    public function upsertRegions(array $regions): void;

    /** @param list<DepartmentRecord> $departments */
    public function upsertDepartments(array $departments): void;

    /**
     * Insère ou met à jour (nom, codes postaux, population, centroïde, réactivation). Le slug d'une commune
     * existante n'est jamais modifié : les URL publiques restent stables.
     *
     * @param list<CommuneRow> $communes
     *
     * @return int nombre de communes nouvelles
     */
    public function upsertCommunes(array $communes): int;

    /** @return array<string, string> slug => code INSEE des communes déjà connues */
    public function slugOwners(): array;

    public function activeCommuneCount(): int;

    /**
     * Désactive les communes absentes de l'import complet (fusions, communes nouvelles).
     *
     * @param list<string> $presentInsee
     */
    public function deactivateMissing(array $presentInsee): int;

    /** BO07.1 : active les pages villes SEO des communes d'au moins $minPopulation habitants ; renvoie le nombre activé. */
    public function enableSeoPages(int $minPopulation): int;
}
