<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Signup;

use App\ArtisanProfile\Domain\Profile\ArtisanProfileId;
use App\ArtisanProfile\Domain\Profile\ArtisanProfileRepository;
use App\ArtisanProfile\Domain\Profile\CompanyDetails;
use App\Shared\Application\Integration\CompanyRegistry;
use App\Shared\Application\Integration\ExternalServiceUnavailable;
use App\Shared\Domain\Exception\InvalidValue;
use App\Shared\Domain\ValueObject\Siret;

/** RG-041 : SIRET valide (clé de Luhn), connu de Sirene, actif et unique sur la plateforme. */
final readonly class CompanyChecker
{
    public function __construct(private CompanyRegistry $registry, private ArtisanProfileRepository $profiles)
    {
    }

    public function lookup(string $siret, ?ArtisanProfileId $ownProfile): CompanyLookupResult
    {
        try {
            $value = new Siret($siret);
        } catch (InvalidValue) {
            return new CompanyLookupResult(CompanyLookupResult::INVALID);
        }
        if ($this->profiles->isSiretTaken($value, $ownProfile)) {
            return new CompanyLookupResult(CompanyLookupResult::TAKEN);
        }
        try {
            $company = $this->registry->find($value);
        } catch (ExternalServiceUnavailable) {
            return new CompanyLookupResult(CompanyLookupResult::UNAVAILABLE, null, CompanyDetails::frenchVatNumber($value));
        }
        if (null === $company) {
            return new CompanyLookupResult(CompanyLookupResult::INVALID);
        }

        return new CompanyLookupResult($company->active ? CompanyLookupResult::FOUND : CompanyLookupResult::CLOSED, $company, CompanyDetails::frenchVatNumber($value));
    }
}
