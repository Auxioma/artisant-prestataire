<?php

declare(strict_types=1);

namespace App\Quote\Application\Pdf;

use App\ArtisanProfile\Application\Quote\QuoteIssuerView;
use App\Quote\Domain\Quote\QuoteContent;

/**
 * PR04.4 — contenu du PDF d'un devis : entreprise (logo, identification, assurance), client (prénom et commune avant
 * acceptation, RG-112), référence de la demande, version, lignes, totaux, conditions et mentions.
 */
final readonly class QuotePdfDocument
{
    public function __construct(
        public QuoteIssuerView $issuer,
        public ?string $logoDataUri,
        public string $clientLabel,
        public string $requestNumber,
        public QuoteContent $content,
        public ?string $number,
        public int $version,
        public \DateTimeImmutable $issuedAt,
        public \DateTimeImmutable $validUntil,
        /** Aperçu d'un brouillon : filigrane, aucun numéro. */
        public bool $preview,
    ) {
    }
}
