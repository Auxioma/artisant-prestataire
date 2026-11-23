<?php

declare(strict_types=1);

namespace App\Search\Application\Offer;

use App\Search\Application\Artisan\SearchCandidate;

/**
 * Carte offre P09.3 : visuel, titre, réduction (`percent` : %, `amount` : €, `free` : prestation offerte), prix de
 * référence (RG-026), artisan (nom, note, ville), date de fin et conditions résumées.
 */
final readonly class OfferCard
{
    public const string PERCENT = 'percent';
    public const string AMOUNT = 'amount';
    public const string FREE = 'free';

    public function __construct(
        public string $id,
        public string $title,
        public string $type,
        public ?float $value,
        public ?int $referencePriceCents,
        public \DateTimeImmutable $endsOn,
        public ?string $conditions,
        public SearchCandidate $artisan,
    ) {
    }
}
