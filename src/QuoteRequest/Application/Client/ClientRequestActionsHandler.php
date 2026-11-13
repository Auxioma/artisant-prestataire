<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Client;

use App\Catalog\Application\Geo\Query\CommunesByInsee;
use App\Catalog\Application\Geo\Query\CommuneSummary;
use App\QuoteRequest\Application\Draft\DraftOwner;
use App\QuoteRequest\Application\Draft\DraftStore;
use App\QuoteRequest\Application\Draft\RequestDraftEditor;
use App\QuoteRequest\Application\Draft\RequestDraftView;
use App\QuoteRequest\Application\Port\RequestContentStore;
use App\QuoteRequest\Domain\Request\PreferredSlot;
use App\QuoteRequest\Domain\Request\QuoteRequest;
use App\QuoteRequest\Domain\Request\QuoteRequestId;
use App\QuoteRequest\Domain\Request\QuoteRequestRepository;
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
 * C02 — actions du client sur ses demandes : clôturer (motif), relancer une demande expirée (une fois, RG-064),
 * dupliquer dans le brouillon du tunnel (P11). Seul le propriétaire agit sur sa demande.
 */
final readonly class ClientRequestActionsHandler implements CommandHandler
{
    public function __construct(
        private QuoteRequestRepository $requests,
        private DraftStore $drafts,
        private RequestContentStore $content,
        private SettingsProvider $settings,
        private QueryBus $queries,
        private EventBus $events,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(CloseQuoteRequest|RelaunchQuoteRequest|DuplicateQuoteRequest $command): void
    {
        $now = $this->clock->now();
        $request = $this->owned($command->clientId, $command->requestId, !$command instanceof DuplicateQuoteRequest);
        if ($command instanceof DuplicateQuoteRequest) {
            $this->duplicate($request, $command->draftId, $now);

            return;
        }
        if ($command instanceof CloseQuoteRequest) {
            $reason = CloseReason::tryFrom($command->reason) ?? throw new RequestRuleViolation('Indiquez pourquoi vous clôturez cette demande.');
            $request->cancel('client_'.$reason->value, $now);
            $action = 'request.closed_by_client';
        } else {
            $request->relaunch($this->settings->int(SettingKey::RequestsExpiryDays), $now);
            $action = 'request.relaunched';
        }
        $this->events->publish(...$request->releaseEvents());
        $this->audit->record(new AuditRecord(
            id: $this->ids->next(),
            occurredAt: $now,
            actorType: 'user',
            actorId: $command->clientId,
            action: $action,
            subjectType: 'quote_request',
            subjectId: $command->requestId,
            context: ['number' => $request->number(), 'status' => $request->status()->value],
        ));
    }

    private function owned(string $clientId, string $requestId, bool $lock): QuoteRequest
    {
        $id = new QuoteRequestId($requestId);
        $request = $lock ? $this->requests->findForUpdate($id) : $this->requests->find($id);
        if (null === $request || $request->clientId() !== $clientId) {
            throw new RequestRuleViolation('Demande introuvable.');
        }

        return $request;
    }

    private function duplicate(QuoteRequest $request, string $draftId, \DateTimeImmutable $now): void
    {
        $owner = DraftOwner::user($request->clientId());
        $details = $request->details();
        $label = $this->content->address($request->id()->value);
        $city = $request->postalCode();
        $communes = $this->queries->ask(new CommunesByInsee([$request->cityInsee()]));
        foreach (\is_array($communes) ? $communes : [] as $commune) {
            $city = $commune instanceof CommuneSummary ? $commune->name : $city;
        }
        $desired = null !== $details->desiredDate && $details->desiredDate > $now ? $details->desiredDate->format('Y-m-d') : null;
        $draft = new RequestDraftView(
            $this->drafts->find($owner)->id ?? $draftId,
            RequestDraftEditor::STEP_RECAP,
            $request->jobId(),
            $details->projectType->value,
            $request->targetArtisanId(),
            $request->broadcastToOthers(),
            $request->questionnaireVersion(),
            $details->answers,
            $details->description,
            $details->budgetRange,
            [],
            $now,
            null === $label ? null : ['label' => $label, 'city_insee' => $request->cityInsee(), 'postal_code' => $request->postalCode(), 'city' => $city, 'lat' => null, 'lon' => null],
            $details->timing->value,
            $desired,
            array_map(static fn (PreferredSlot $s): string => $s->value, $details->preferredSlots),
            $details->occupantStatus?->value,
            $request->contactPreference()->value,
            $details->title,
            $details->summary,
            $details->workNature,
            source: 'other',
        );
        $this->drafts->save($owner, $draft, $now->modify(\sprintf('+%d days', $this->settings->int(SettingKey::RequestsDraftRetentionDays))));
    }
}
