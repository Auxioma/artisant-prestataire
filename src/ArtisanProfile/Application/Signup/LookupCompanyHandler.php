<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Signup;

use App\ArtisanProfile\Domain\Profile\ArtisanProfileRepository;
use App\Shared\Application\Bus\QueryHandler;

final readonly class LookupCompanyHandler implements QueryHandler
{
    public function __construct(private CompanyChecker $checker, private ArtisanProfileRepository $profiles)
    {
    }

    public function __invoke(LookupCompany $query): CompanyLookupResult
    {
        return $this->checker->lookup($query->siret, $this->profiles->findByUserId($query->userId)?->id());
    }
}
