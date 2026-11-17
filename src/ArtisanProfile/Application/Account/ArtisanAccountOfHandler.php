<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Account;

use App\ArtisanProfile\Domain\Profile\ArtisanProfileId;
use App\ArtisanProfile\Domain\Profile\ArtisanProfileRepository;
use App\Shared\Application\Bus\QueryHandler;

final readonly class ArtisanAccountOfHandler implements QueryHandler
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(private ArtisanProfileRepository $profiles)
    {
    }

    public function __invoke(ArtisanAccountOf $query): ?ArtisanAccount
    {
        $profile = 1 === preg_match(self::UUID, $query->profileId) ? $this->profiles->find(new ArtisanProfileId($query->profileId)) : null;

        return null === $profile ? null : new ArtisanAccount($profile->userId(), $profile->tradeName() ?? $profile->companyName() ?? 'Votre professionnel');
    }
}
