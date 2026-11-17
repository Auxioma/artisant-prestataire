<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Infrastructure\Doctrine\Type;

use App\ArtisanProfile\Domain\Profile\ArtisanProfileId;
use App\Shared\Infrastructure\Doctrine\Type\AggregateIdType;

final class ArtisanProfileIdType extends AggregateIdType
{
    public const string NAME = 'artisan_profile_id';

    protected function idClass(): string
    {
        return ArtisanProfileId::class;
    }
}
