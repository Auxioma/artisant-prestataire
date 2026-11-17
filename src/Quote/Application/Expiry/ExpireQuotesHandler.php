<?php

declare(strict_types=1);

namespace App\Quote\Application\Expiry;

use App\Quote\Domain\Quote\QuoteId;
use App\Quote\Domain\Quote\QuoteRepository;
use App\QuoteRequest\Domain\Request\QuoteRequestId;
use App\QuoteRequest\Domain\Request\QuoteRequestRepository;
use App\QuoteRequest\Domain\Request\RequestStatus;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/**
 * RG-121 — passage en « expiré » des devis sans décision à la fin de leur validité. La place est libérée si la demande
 * accepte encore des devis (une demande complète redevient ouverte, P-09) ; verrous demande puis devis (ADR-0006).
 */
final readonly class ExpireQuotesHandler implements CommandHandler
{
    public const string RELEASE_REASON = 'quote_expired';

    public function __construct(
        private QuoteRepository $quotes,
        private QuoteRequestRepository $requests,
        private EventBus $events,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(ExpireQuotes $command): void
    {
        $now = $this->clock->now();
        foreach ($this->quotes->expiredIds($now, ExpireQuotes::BATCH) as $id) {
            $quote = $this->quotes->find(new QuoteId($id));
            $request = null === $quote ? null : $this->requests->findForUpdate(new QuoteRequestId($quote->requestId()));
            $quote = $this->quotes->findForUpdate(new QuoteId($id));
            if (null === $quote || null === $request || !$quote->status()->isRevisable()) {
                continue;
            }
            $quote->expire($now);
            if (\in_array($request->status(), [RequestStatus::Open, RequestStatus::Full], true) && $request->activeQuotesCount() > 0) {
                $request->releaseQuote($now, self::RELEASE_REASON);
            }
            $this->quotes->save($quote);
            $this->events->publish(...$quote->releaseEvents(), ...$request->releaseEvents());
            $this->audit->record(new AuditRecord(
                id: $this->ids->next(),
                occurredAt: $now,
                actorType: 'system',
                actorId: null,
                action: 'quote.expired',
                subjectType: 'quote',
                subjectId: $id,
                context: ['number' => $quote->number(), 'request' => $request->number()],
            ));
        }
    }
}
