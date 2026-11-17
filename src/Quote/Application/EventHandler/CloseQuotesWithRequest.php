<?php

declare(strict_types=1);

namespace App\Quote\Application\EventHandler;

use App\Billing\Application\Credit\QuoteCreditAccount;
use App\Quote\Domain\Quote\QuoteRepository;
use App\QuoteRequest\Application\Moderation\ModerateQuoteRequestsHandler;
use App\QuoteRequest\Domain\Request\Event\QuoteRequestStatusChanged;
use App\QuoteRequest\Domain\Request\RequestStatus;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Application\Bus\EventHandler;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/**
 * RG-121 — demande annulée (par le client, l'équipe ou pour fraude) : ses devis encore à décider passent en « demande
 * annulée ». Q2 — suppression pour fraude : le crédit de chaque devis envoyé est restitué à l'artisan
 * (`credits.refund_on_fraud_deletion`), une seule fois (registre idempotent).
 */
final readonly class CloseQuotesWithRequest implements EventHandler
{
    public function __construct(
        private QuoteRepository $quotes,
        private QuoteCreditAccount $credits,
        private EventBus $events,
        private SettingsProvider $settings,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(QuoteRequestStatusChanged $event): void
    {
        if (RequestStatus::Cancelled->value !== $event->to) {
            return;
        }
        $now = $this->clock->now();
        $refund = ModerateQuoteRequestsHandler::FRAUD_REASON === $event->reason && $this->settings->bool(SettingKey::CreditsRefundOnFraudDeletion);
        foreach ($this->quotes->sentForRequest($event->requestId, true) as $found) {
            $quote = $this->quotes->findForUpdate($found->id()) ?? $found;
            if ($quote->status()->isRevisable()) {
                $quote->cancelWithRequest($now);
                $this->quotes->save($quote);
                $this->events->publish(...$quote->releaseEvents());
            }
            if ($refund && $this->credits->refundQuote($this->ids->next(), $quote->artisanId(), $quote->id()->value, $now)) {
                $this->audit->record(new AuditRecord(
                    id: $this->ids->next(),
                    occurredAt: $now,
                    actorType: 'system',
                    actorId: null,
                    action: 'credit.refunded_fraud',
                    subjectType: 'quote',
                    subjectId: $quote->id()->value,
                    context: ['number' => $quote->number(), 'request' => $event->requestId, 'artisan' => $quote->artisanId()],
                ));
            }
        }
    }
}
