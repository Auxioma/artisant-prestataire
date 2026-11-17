<?php

declare(strict_types=1);

namespace App\Quote\Application\Pro;

use App\ArtisanProfile\Application\Quote\QuoteIssuer;
use App\ArtisanProfile\Application\Quote\QuoteIssuerView;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Bus\QueryHandler;

/** PR05 — liste des devis de l'artisan connecté (jamais ceux d'un autre), synthèse « en chiffres ». */
final readonly class ProQuotesHandler implements QueryHandler
{
    public const int PER_PAGE = 10;

    public function __construct(private QueryBus $queries, private ProQuoteReader $reader)
    {
    }

    public function __invoke(ProQuotes $query): ?ProQuotesPage
    {
        $issuer = $this->queries->ask(new QuoteIssuer($query->userId));
        if (!$issuer instanceof QuoteIssuerView) {
            return null;
        }
        $perPage = $query->perPage ?? self::PER_PAGE;
        $page = max(1, $query->page);
        $result = $this->reader->page($issuer->artisanId, $query->filter, $page, $perPage);
        $stats = $this->reader->stats($issuer->artisanId);

        return new ProQuotesPage(
            $result['items'],
            $result['total'],
            $page,
            $perPage,
            ['pending' => $stats['pending'], 'accepted' => $stats['accepted'], 'works' => $stats['works'], 'done' => $stats['done']],
            ['devis' => $stats['devis'], 'chantiers' => $stats['chantiers']],
            $this->reader->jobs($issuer->artisanId),
        );
    }
}
