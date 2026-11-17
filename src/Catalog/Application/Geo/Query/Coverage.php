<?php

declare(strict_types=1);

namespace App\Catalog\Application\Geo\Query;

final readonly class Coverage
{
    public function __construct(public int $communes, public int $population)
    {
    }
}
