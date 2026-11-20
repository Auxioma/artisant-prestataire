<?php

declare(strict_types=1);

namespace App\Search\Application\Query;

use App\Search\Application\Port\JobDocument;
use App\Search\Application\Port\PlaceDocument;

final readonly class Suggestions
{
    /**
     * @param list<JobDocument>   $jobs
     * @param list<PlaceDocument> $places
     */
    public function __construct(public array $jobs, public array $places, public bool $degraded)
    {
    }
}
