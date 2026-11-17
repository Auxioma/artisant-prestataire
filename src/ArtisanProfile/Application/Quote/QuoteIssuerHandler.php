<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Quote;

use App\ArtisanProfile\Application\Signup\SignupRequirements;
use App\ArtisanProfile\Domain\Profile\ArtisanProfileRepository;
use App\ArtisanProfile\Domain\Profile\ArtisanStatus;
use App\ArtisanProfile\Domain\Profile\Insurance;
use App\ArtisanProfile\Domain\Profile\VatStatus;
use App\Shared\Application\Bus\QueryHandler;

final readonly class QuoteIssuerHandler implements QueryHandler
{
    public function __construct(private ArtisanProfileRepository $profiles, private SignupRequirements $requirements)
    {
    }

    public function __invoke(QuoteIssuer $query): ?QuoteIssuerView
    {
        $profile = $this->profiles->findByUserId($query->userId);
        if (null === $profile) {
            return null;
        }
        $required = $this->requirements->insurances($profile);
        $address = trim(implode(' ', array_filter([$profile->hqStreet(), $profile->hqPostalCode(), $profile->hqCity()])));

        return new QuoteIssuerView(
            $profile->id()->value,
            ArtisanStatus::Verified === $profile->status() && !$profile->isHidden(),
            $profile->tradeName() ?? $profile->companyName() ?? '',
            $profile->companyName(),
            $profile->siret()?->value,
            '' === $address ? null : $address,
            VatStatus::Franchise === $profile->vatStatus(),
            $profile->vatNumber(),
            array_map(static fn (Insurance $i): array => ['kind' => $i->kind->value, 'label' => $i->kind->label(), 'insurer' => $i->insurer, 'policy' => $i->policyNumber, 'validUntil' => $i->validUntil], $profile->insurances()),
            $required->any,
            $required->decennial,
            $profile->isPublic() && null !== $profile->logoFileId(),
            $profile->isPublic() ? $profile->logoFileId() : null,
        );
    }
}
