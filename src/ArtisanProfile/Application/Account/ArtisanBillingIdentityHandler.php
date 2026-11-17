<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Account;

use App\ArtisanProfile\Domain\Profile\ArtisanProfileRepository;
use App\Shared\Application\Billing\ArtisanBillingIdentity;
use App\Shared\Application\Billing\ArtisanBillingIdentityDetails;
use App\Shared\Application\Bus\QueryHandler;

/** PR12.2 — coordonnées de l'entreprise relevées à l'inscription (Sirene), proposées pour la facturation. */
final readonly class ArtisanBillingIdentityHandler implements QueryHandler
{
    public function __construct(private ArtisanProfileRepository $profiles)
    {
    }

    public function __invoke(ArtisanBillingIdentity $query): ?ArtisanBillingIdentityDetails
    {
        $profile = $this->profiles->findByUserId($query->userId);

        return null === $profile ? null : new ArtisanBillingIdentityDetails($profile->companyName(), $profile->hqStreet(), $profile->hqPostalCode(), $profile->hqCity(), $profile->vatNumber());
    }
}
