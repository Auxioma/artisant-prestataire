<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Moderation;

use App\QuoteRequest\Domain\Request\QuoteRequestId;
use App\QuoteRequest\Domain\Request\QuoteRequestRepository;
use App\QuoteRequest\Domain\Request\RejectionReason;
use App\QuoteRequest\Domain\Request\RequestRuleViolation;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/** BO04.1 — valider / refuser / clôturer, demande par demande (verrou `FOR UPDATE`), tout ou rien sur la sélection. */
final readonly class ModerateQuoteRequestsHandler implements CommandHandler
{
    private const string CLOSE_REASON = 'closed_by_admin';
    private const int COMMENT_MAX = 500;
    private const int SELECTION_MAX = 100;

    public function __construct(
        private QuoteRequestRepository $requests,
        private SettingsProvider $settings,
        private EventBus $events,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(ModerateQuoteRequests $command): void
    {
        if ([] === $command->requestIds || \count($command->requestIds) > self::SELECTION_MAX) {
            throw new ModerationRejected(\sprintf('Sélectionnez entre 1 et %d demandes.', self::SELECTION_MAX));
        }
        $reason = null;
        if (ModerateQuoteRequests::REJECT === $command->decision) {
            $reason = RejectionReason::tryFrom($command->reason ?? '') ?? throw new ModerationRejected('Choisissez un motif de refus.');
        }
        $comment = mb_substr(trim($command->comment ?? ''), 0, self::COMMENT_MAX);
        $now = $this->clock->now();

        foreach (array_values(array_unique($command->requestIds)) as $id) {
            if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id)) {
                throw new ModerationRejected('Demande introuvable.');
            }
            $request = $this->requests->findForUpdate(new QuoteRequestId($id)) ?? throw new ModerationRejected('Demande introuvable.');
            try {
                match ($command->decision) {
                    ModerateQuoteRequests::APPROVE => $request->approve($this->settings->int(SettingKey::RequestsExpiryDays), $now),
                    ModerateQuoteRequests::REJECT => $request->reject((string) $reason?->value, $now),
                    ModerateQuoteRequests::CLOSE => $request->cancel(self::CLOSE_REASON, $now),
                    default => throw new ModerationRejected('Décision inconnue.'),
                };
            } catch (RequestRuleViolation $e) {
                throw new ModerationRejected(\sprintf('Demande %s : %s', $request->number(), $e->getMessage()));
            }
            $this->events->publish(...$request->releaseEvents());
            $this->audit->record(new AuditRecord(
                id: $this->ids->next(),
                occurredAt: $now,
                actorType: 'admin',
                actorId: $command->adminId,
                action: 'request.moderation_'.$command->decision,
                subjectType: 'quote_request',
                subjectId: $id,
                context: array_filter(['number' => $request->number(), 'reason' => $reason?->value, 'comment' => '' === $comment ? null : $comment]),
            ));
        }
    }
}
