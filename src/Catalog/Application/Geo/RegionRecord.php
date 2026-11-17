<?php

declare(strict_types=1);

namespace App\Catalog\Application\Geo;

final readonly class RegionRecord
{
    public function __construct(public string $code, public string $name)
    {
    }
}
