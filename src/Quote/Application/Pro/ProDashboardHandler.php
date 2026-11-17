<?php

declare(strict_types=1);

namespace App\Quote\Application\Pro;

use App\ArtisanProfile\Application\Completion\ProfileCompletionOf;
use App\ArtisanProfile\Application\Quote\QuoteIssuer;
use App\ArtisanProfile\Application\Quote\QuoteIssuerView;
use App\ArtisanProfile\Domain\Profile\ProfileCompletion;
use App\QuoteRequest\Application\Pro\AvailableRequests;
use App\QuoteRequest\Application\Pro\ProRequestFilter;
use App\QuoteRequest\Application\Pro\ProRequestPage;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Bus\QueryHandler;

/** PR01 — synthèse de l'activité : demandes nouvelles et récentes (PR02), devis (PR05), activité de la période, profil. */
final readonly class ProDashboardHandler implements QueryHandler
{
    public const int LATEST = 3;

    public function __construct(private QueryBus $queries, private ProQuoteReader $reader)
    {
    }

    public function __invoke(ProDashboard $query): ?ProDashboardView
    {
        $issuer = $this->queries->ask(new QuoteIssuer($query->userId));
        if (!$issuer instanceof QuoteIssuerView) {
            return null;
        }
        $requests = $this->queries->ask(new AvailableRequests($query->userId, new ProRequestFilter(), 1, self::LATEST));
        $completion = $this->queries->ask(new ProfileCompletionOf($query->userId));
        $stats = $this->reader->stats($issuer->artisanId);

        return new ProDashboardView(
            $requests instanceof ProRequestPage ? $requests->stats['unread'] : 0,
            $requests instanceof ProRequestPage ? array_values($requests->items) : [],
            ['pending' => $stats['pending'], 'accepted' => $stats['accepted'], 'works' => $stats['works'], 'done' => $stats['done']],
            $this->reader->clients($issuer->artisanId),
            $this->reader->activity($issuer->artisanId, $query->days),
            $query->days,
            $completion instanceof ProfileCompletion ? $completion->percent : null,
            $issuer->verified,
        );
    }
}
