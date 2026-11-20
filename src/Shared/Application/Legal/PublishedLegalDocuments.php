<?php

declare(strict_types=1);

namespace App\Shared\Application\Legal;

use App\Shared\Application\Bus\Query;

/**
 * Pages légales en vigueur (P17), pour le sitemap et les liens des autres modules. Traitée par Identity ; réponse :
 * array<string, \DateTimeImmutable> (slug de la page => date de publication de la version en vigueur).
 */
final readonly class PublishedLegalDocuments implements Query
{
}
