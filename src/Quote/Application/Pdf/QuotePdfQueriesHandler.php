<?php

declare(strict_types=1);

namespace App\Quote\Application\Pdf;

use App\ArtisanProfile\Application\Quote\QuoteIssuer;
use App\ArtisanProfile\Application\Quote\QuoteIssuerView;
use App\Quote\Domain\Quote\QuoteId;
use App\Quote\Domain\Quote\QuoteRepository;
use App\Quote\Domain\Quote\QuoteStatus;
use App\QuoteRequest\Application\Pro\ProRequestDetail;
use App\QuoteRequest\Application\Pro\ProRequestView;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Domain\Time\Clock;

final readonly class QuotePdfQueriesHandler implements QueryHandler
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(private QuoteRepository $quotes, private QuotePdfService $pdf, private QueryBus $queries, private Clock $clock)
    {
    }

    public function __invoke(QuotePreviewPdf|QuoteVersionPdf $query): ?string
    {
        $issuer = $this->queries->ask(new QuoteIssuer($query->userId));
        if (!$issuer instanceof QuoteIssuerView) {
            return null;
        }
        if ($query instanceof QuoteVersionPdf) {
            $quote = 1 === preg_match(self::UUID, $query->quoteId) ? $this->quotes->find(new QuoteId($query->quoteId)) : null;
            $pdf = null === $quote || $quote->artisanId() !== $issuer->artisanId ? null : $quote->version($query->version)?->pdf;

            return null === $pdf ? null : $this->pdf->read($pdf);
        }
        $request = $this->queries->ask(new ProRequestDetail($query->userId, $query->requestId));
        $quote = $this->quotes->findByRequestAndArtisan($query->requestId, $issuer->artisanId);
        if (!$request instanceof ProRequestView || null === $quote) {
            return null;
        }
        $draft = QuoteStatus::Draft === $quote->status();

        return $this->pdf->preview($this->pdf->document($issuer, $request, $quote->current()->content, $draft ? null : $quote->number(), $quote->current()->number, $draft ? $this->clock->now() : ($quote->current()->sentAt ?? $this->clock->now()), $draft));
    }
}
