<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Type;

use App\Catalog\Domain\Job\JobId;
use App\Shared\Infrastructure\Doctrine\Type\AggregateIdType;

final class JobIdType extends AggregateIdType
{
    public const string NAME = 'catalog_job_id';

    protected function idClass(): string
    {
        return JobId::class;
    }
}
