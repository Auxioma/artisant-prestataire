<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Signup;

use App\Shared\Application\Integration\CompanyRecord;

/** Résultat du bouton « Vérifier » (A03.2). */
final readonly class CompanyLookupResult
{
    public const string FOUND = 'found';
    public const string INVALID = 'invalid';
    public const string TAKEN = 'taken';
    public const string CLOSED = 'closed';
    public const string UNAVAILABLE = 'unavailable';

    public function __construct(public string $status, public ?CompanyRecord $company = null, public ?string $suggestedVatNumber = null)
    {
    }
}
