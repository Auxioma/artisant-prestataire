<?php

declare(strict_types=1);

namespace App\Catalog\Application\Geo\Query;

/** Commune et réglages de sa page SEO (BO07.1) : page activée, paragraphe local personnalisé. */
final readonly class SeoCommune
{
    public function __construct(public CommuneSummary $commune, public bool $pageEnabled, public ?string $seoText)
    {
    }
}
