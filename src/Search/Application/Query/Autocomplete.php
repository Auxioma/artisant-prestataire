<?php

declare(strict_types=1);

namespace App\Search\Application\Query;

use App\Shared\Application\Bus\Query;

/**
 * P01.2 : suggestions dès 2 caractères — métiers et synonymes, communes et codes postaux ; 8 suggestions maximum.
 */
final readonly class Autocomplete implements Query
{
    public const string SCOPE_JOBS = 'metier';
    public const string SCOPE_PLACES = 'lieu';
    public const int MIN_LENGTH = 2;
    public const int MAX_LENGTH = 100;
    public const int MAX_SUGGESTIONS = 8;

    public function __construct(public string $term, public string $scope)
    {
    }
}
