<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Moderation;

use App\Catalog\Application\Query\JobIndexEntries;
use App\Catalog\Application\Query\JobIndexEntry;
use App\QuoteRequest\Domain\Request\QuoteRequestId;
use App\QuoteRequest\Domain\Request\QuoteRequestRepository;
use App\QuoteRequest\Domain\Request\RejectionReason;
use App\QuoteRequest\Domain\Request\RequestRuleViolation;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/**
 * BO04.1 — valider / refuser / clôturer / supprimer pour fraude (motif obligatoire, Q2), demande par demande (verrou
 * `FOR UPDATE`), tout ou rien sur la sélection.
 */
final readonly class ModerateQuoteRequestsHandler implements CommandHandler
{
    private const string CLOSE_REASON = 'closed_by_admin';
    public const string FRAUD_REASON = 'deleted_fraud';
    private const int COMMENT_MAX = 500;
    private const int SELECTION_MAX = 100;

    public function __construct(
        private QuoteRequestRepository $requests,
        private SettingsProvider $settings,
        private EventBus $events,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
        private QueryBus $queries,
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
        if (ModerateQuoteRequests::DELETE === $command->decision && '' === $comment) {
            throw new ModerationRejected('Indiquez le motif de la suppression (fraude constatée).');
        }
        $now = $this->clock->now();

        foreach (array_values(array_unique($command->requestIds)) as $id) {
            if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id)) {
                throw new ModerationRejected('Demande introuvable.');
            }
            $request = $this->requests->findForUpdate(new QuoteRequestId($id)) ?? throw new ModerationRejected('Demande introuvable.');
            if (ModerateQuoteRequests::APPROVE === $command->decision && !$this->jobPublished($request->jobId())) {
                throw new ModerationRejected(\sprintf('Demande %s : validez d’abord le métier proposé par le client (Référentiel métiers › Propositions).', $request->number()));
            }
            try {
                match ($command->decision) {
                    ModerateQuoteRequests::APPROVE => $request->approve($this->settings->int(SettingKey::RequestsExpiryDays), $now),
                    ModerateQuoteRequests::REJECT => $request->reject((string) $reason?->value, $now),
                    ModerateQuoteRequests::CLOSE => $request->cancel(self::CLOSE_REASON, $now),
                    ModerateQuoteRequests::DELETE => $request->cancel(self::FRAUD_REASON, $now),
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

    /** BO06 : une demande sur un métier proposé non encore validé ne peut pas être diffusée. */
    private function jobPublished(string $jobId): bool
    {
        $entries = $this->queries->ask(new JobIndexEntries([$jobId]));
        foreach (\is_array($entries) ? $entries : [] as $entry) {
            if ($entry instanceof JobIndexEntry && $entry->id === $jobId) {
                return $entry->searchable;
            }
        }

        return false;
    }
}
