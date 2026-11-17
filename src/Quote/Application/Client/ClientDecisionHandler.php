<?php

declare(strict_types=1);

namespace App\Quote\Application\Client;

use App\ArtisanProfile\Application\Agenda\SlotReservations;
use App\Quote\Domain\Appointment\Appointment;
use App\Quote\Domain\Appointment\AppointmentParty;
use App\Quote\Domain\Appointment\AppointmentRepository;
use App\Quote\Domain\Appointment\AppointmentStatus;
use App\Quote\Domain\Quote\ClientRefusalReason;
use App\Quote\Domain\Quote\Quote;
use App\Quote\Domain\Quote\QuoteId;
use App\Quote\Domain\Quote\QuoteRepository;
use App\Quote\Domain\Quote\QuoteRuleViolation;
use App\Quote\Domain\Quote\QuoteStatus;
use App\QuoteRequest\Domain\Request\QuoteRequestId;
use App\QuoteRequest\Domain\Request\QuoteRequestRepository;
use App\QuoteRequest\Domain\Request\RequestRuleViolation;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/**
 * C03 — décisions du client sur les devis de sa demande. Acceptation (RG-071, RG-072) : verrous demande puis devis
 * (ADR-0006), un seul devis accepté, demande « attribuée », autres devis encore à décider « non retenus ». Refus motivé.
 * Consultation : « consulté » à la première ouverture.
 */
final readonly class ClientDecisionHandler implements CommandHandler
{
    public const int COMMENT_MAX = 300;

    public function __construct(
        private ClientQuotesHandler $access,
        private QuoteRepository $quotes,
        private QuoteRequestRepository $requests,
        private AppointmentRepository $appointments,
        private SlotReservations $slots,
        private SettingsProvider $settings,
        private EventBus $events,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(MarkQuoteViewed|AcceptQuote|RefuseQuote|CancelAcceptance $command): void
    {
        match (true) {
            $command instanceof MarkQuoteViewed => $this->view($command),
            $command instanceof AcceptQuote => $this->accept($command),
            $command instanceof CancelAcceptance => $this->cancelAcceptance($command),
            default => $this->refuse($command),
        };
    }

    /**
     * RG-072 — acceptation annulée avec motif, au plus tard `quotes.cancel_acceptance_min_hours_before_appointment`
     * heures avant le rendez-vous confirmé : rendez-vous annulé (créneau rouvert), devis « refusé » (E-35 à l'artisan),
     * demande de nouveau ouverte ou complète, place libérée.
     */
    private function cancelAcceptance(CancelAcceptance $command): void
    {
        $reason = trim($command->reason);
        if ('' === $reason || mb_strlen($reason) > self::COMMENT_MAX) {
            throw new QuoteRuleViolation(\sprintf('Indiquez le motif de l’annulation (%d caractères maximum).', self::COMMENT_MAX));
        }
        $found = $this->owned($command->userId, $command->quoteId);
        $request = $this->requests->findForUpdate(new QuoteRequestId($found->requestId())) ?? throw new QuoteRuleViolation('Demande introuvable.');
        $quote = $this->quotes->findForUpdate($found->id()) ?? throw new QuoteRuleViolation('Devis introuvable.');
        $now = $this->clock->now();
        $appointment = $this->appointments->activeForQuote($quote->id()->value);
        $minHours = $this->settings->int(SettingKey::QuotesCancelAcceptanceMinHoursBeforeAppointment);
        if (null !== $appointment && AppointmentStatus::Confirmed === $appointment->status() && $appointment->startsAt() < $now->modify(\sprintf('+%d hours', $minHours))) {
            throw new QuoteRuleViolation(\sprintf('Le rendez-vous a lieu dans moins de %d h : l’acceptation ne peut plus être annulée. Contactez le professionnel via la messagerie.', $minHours));
        }
        $quote->cancelAcceptance($reason, $now);
        try {
            $request->revokeAward('acceptance_cancelled', $now);
            $request->releaseQuote($now, 'acceptance_cancelled');
        } catch (RequestRuleViolation) {
            throw new QuoteRuleViolation('Cette demande est terminée ou close : l’acceptation ne peut plus être annulée.');
        }
        $events = [];
        if (null !== $appointment) {
            $appointment->cancel(AppointmentParty::Client, mb_substr('Acceptation du devis annulée — '.$reason, 0, Appointment::REASON_MAX), $now);
            $this->slots->release($appointment->id()->value, $now);
            $this->appointments->save($appointment);
            $events = $appointment->releaseEvents();
        }
        $this->quotes->save($quote);
        $this->events->publish(...$quote->releaseEvents(), ...$events, ...$request->releaseEvents());
        $this->record($command->userId, 'quote.acceptance_cancelled', $quote, ['request' => $request->number(), 'reason' => $reason], $now);
    }

    private function view(MarkQuoteViewed $command): void
    {
        $quote = $this->owned($command->userId, $command->quoteId);
        if (QuoteStatus::Sent !== $quote->status()) {
            return;
        }
        $quote->markViewed($this->clock->now());
        $this->quotes->save($quote);
        $this->events->publish(...$quote->releaseEvents());
    }

    private function accept(AcceptQuote $command): void
    {
        $found = $this->owned($command->userId, $command->quoteId);
        $request = $this->requests->findForUpdate(new QuoteRequestId($found->requestId())) ?? throw new QuoteRuleViolation('Demande introuvable.');
        $quote = $this->quotes->findForUpdate($found->id()) ?? throw new QuoteRuleViolation('Devis introuvable.');
        $now = $this->clock->now();
        if (!$quote->status()->isRevisable()) {
            throw new QuoteRuleViolation('Ce devis ne peut plus être accepté.');
        }
        if (null === $quote->validUntil() || $now >= $quote->validUntil()) {
            throw new QuoteRuleViolation('Ce devis n’est plus valable : demandez au professionnel de vous en envoyer une nouvelle version.');
        }
        try {
            $request->award($now);
        } catch (RequestRuleViolation) {
            throw new QuoteRuleViolation('Un devis a déjà été accepté sur cette demande, ou elle est close.');
        }
        $quote->accept($now);
        $this->quotes->save($quote);
        $events = $quote->releaseEvents();
        $others = 0;
        foreach ($this->quotes->sentForRequest($quote->requestId()) as $other) {
            if ($other->id()->value === $quote->id()->value || !$other->status()->isRevisable()) {
                continue;
            }
            $other = $this->quotes->findForUpdate($other->id()) ?? $other;
            $other->markNotSelected($now);
            $this->quotes->save($other);
            $events = [...$events, ...$other->releaseEvents()];
            ++$others;
        }
        $this->events->publish(...$events, ...$request->releaseEvents());
        $this->record($command->userId, 'quote.accepted', $quote, ['request' => $request->number(), 'ttc_cents' => $quote->current()->content->totals->ttc->amount, 'not_selected' => $others], $now);
    }

    private function refuse(RefuseQuote $command): void
    {
        $reason = ClientRefusalReason::tryFrom($command->reason) ?? throw new QuoteRuleViolation('Choisissez le motif du refus.');
        $comment = trim((string) $command->comment);
        if (mb_strlen($comment) > self::COMMENT_MAX) {
            throw new QuoteRuleViolation(\sprintf('Votre commentaire est trop long (%d caractères maximum).', self::COMMENT_MAX));
        }
        $quote = $this->quotes->findForUpdate($this->owned($command->userId, $command->quoteId)->id()) ?? throw new QuoteRuleViolation('Devis introuvable.');
        if (!$quote->status()->isRevisable()) {
            throw new QuoteRuleViolation('Ce devis ne peut plus être refusé.');
        }
        $now = $this->clock->now();
        $quote->refuse('' === $comment ? $reason->label() : $reason->label().' — '.$comment, $now);
        $this->quotes->save($quote);
        $this->events->publish(...$quote->releaseEvents());
        $this->record($command->userId, 'quote.refused', $quote, ['reason' => $reason->value], $now);
    }

    private function owned(string $userId, string $quoteId): Quote
    {
        $quote = 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $quoteId) ? $this->quotes->find(new QuoteId($quoteId)) : null;
        if (null === $quote || !ClientQuotesHandler::visible($quote) || null === $this->access->ownedRequest($userId, $quote->requestId())) {
            throw new QuoteRuleViolation('Devis introuvable.');
        }

        return $quote;
    }

    /** @param array<string, mixed> $context */
    private function record(string $userId, string $action, Quote $quote, array $context, \DateTimeImmutable $now): void
    {
        $this->audit->record(new AuditRecord(
            id: $this->ids->next(),
            occurredAt: $now,
            actorType: 'user',
            actorId: $userId,
            action: $action,
            subjectType: 'quote',
            subjectId: $quote->id()->value,
            context: ['number' => $quote->number()] + $context,
        ));
    }
}
