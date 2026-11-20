<?php

declare(strict_types=1);

namespace App\Shared\Application\Integration;

use App\Shared\Domain\ValueObject\Siret;

/** Répertoire des entreprises (A03 étape 2, RG-041) — API Sirene de l'INSEE. */
interface CompanyRegistry
{
    /**
     * @return CompanyRecord|null null si le SIRET est inconnu du répertoire
     *
     * @throws ExternalServiceUnavailable service injoignable, quota dépassé, clé absente ou établissement non diffusible
     */
    public function find(Siret $siret): ?CompanyRecord;
}
