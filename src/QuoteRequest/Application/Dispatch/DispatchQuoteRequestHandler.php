<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Dispatch;

use App\QuoteRequest\Application\Port\DispatchTargets;
use App\QuoteRequest\Application\Port\RequestDispatches;
use App\QuoteRequest\Application\Port\RequestMailer;
use App\QuoteRequest\Domain\Request\QuoteRequestId;
use App\QuoteRequest\Domain\Request\QuoteRequestRepository;
use App\QuoteRequest\Domain\Request\RequestRuleViolation;
use App\QuoteRequest\Domain\Request\RequestStatus;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Identity\UserContact;
use App\Shared\Application\Identity\UserContactDetails;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/**
 * RG-062 — diffusion d'une demande ouverte. Demande directe : l'artisan choisi la reçoit en priorité (E-31, immédiat) ;
 * si le client accepte d'autres devis (ou sans artisan choisi), tous les artisans éligibles la reçoivent (E-30 immédiat,
 * ou en attente de leur digest). Rejouée (réouverture, re-diffusion), elle n'alerte que les nouveaux éligibles. Aucun
 * destinataire : la demande reste publiée et l'équipe est alertée (BO04, journal d'audit). L'équipe peut la re-diffuser
 * ou l'étendre aux artisans du métier proches de la commune (BO04, raison `extended`).
 */
final readonly class DispatchQuoteRequestHandler implements CommandHandler
{
    public function __construct(
        private QuoteRequestRepository $requests,
        private DispatchTargets $targets,
        private RequestDispatches $dispatches,
        private RequestMailer $mailer,
        private QueryBus $queries,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(DispatchQuoteRequest $command): void
    {
        $request = $this->requests->find(new QuoteRequestId($command->requestId));
        $summary = $this->dispatches->summary($command->requestId);
        if (null === $request || null === $summary || RequestStatus::Open !== $request->status()) {
            if (null !== $command->adminId) {
                throw new RequestRuleViolation('Seule une demande ouverte peut être diffusée.');
            }

            return; // en modération, refusée ou déjà close : rien à diffuser
        }
        $now = $this->clock->now();
        $target = $request->targetArtisanId();
        $sent = ['direct' => 0, 'immediate' => 0, 'digest' => 0, 'extended' => 0];

        $direct = null === $target ? null : $this->targets->direct($target, $request->clientId());
        if (null !== $direct && $this->dispatches->record($command->requestId, $direct->artisanId, DispatchReason::Direct, $now, true)) {
            $this->alert($direct, $summary, true);
            ++$sent['direct'];
        }
        if (null === $target || $request->broadcastToOthers()) {
            foreach ($this->targets->eligible($request->jobId(), $request->cityInsee(), $request->clientId()) as $eligible) {
                $this->broadcast($command->requestId, $eligible, $target, DispatchReason::Eligible, $summary, $now, $sent);
            }
            if (null !== $command->extendKm) {
                foreach ($this->targets->extended($request->jobId(), $request->cityInsee(), $request->clientId(), $command->extendKm) as $nearby) {
                    if ($this->broadcast($command->requestId, $nearby, $target, DispatchReason::Extended, $summary, $now, $sent)) {
                        ++$sent['extended'];
                    }
                }
            }
        }

        $total = $this->dispatches->countFor($command->requestId);
        $this->audit->record(new AuditRecord(
            id: $this->ids->next(),
            occurredAt: $now,
            actorType: null === $command->adminId ? 'system' : 'admin',
            actorId: $command->adminId,
            action: match (true) {
                null !== $command->extendKm => 'request.dispatch_extended',
                null !== $command->adminId => 'request.redispatched',
                0 === $total => 'request.dispatch_empty',
                default => 'request.dispatched',
            },
            subjectType: 'quote_request',
            subjectId: $command->requestId,
            context: $sent + ['total' => $total] + (null === $command->extendKm ? [] : ['km' => $command->extendKm]),
        ));
    }

    /**
     * Ajoute un artisan aux destinataires (sauf l'artisan de la demande directe, déjà servi) : alerte immédiate ou digest.
     *
     * @param array<string, int> $sent
     */
    private function broadcast(string $requestId, DispatchTarget $artisan, ?string $direct, DispatchReason $reason, DispatchSummary $summary, \DateTimeImmutable $now, array &$sent): bool
    {
        $immediate = AlertFrequency::Immediate === $artisan->frequency;
        if ($artisan->artisanId === $direct || !$this->dispatches->record($requestId, $artisan->artisanId, $reason, $now, $immediate)) {
            return false;
        }
        if ($immediate) {
            $this->alert($artisan, $summary, false);
        }
        ++$sent[$immediate ? 'immediate' : 'digest'];

        return true;
    }

    private function alert(DispatchTarget $artisan, DispatchSummary $summary, bool $direct): void
    {
        $contact = $this->queries->ask(new UserContact($artisan->userId));
        if ($contact instanceof UserContactDetails) {
            $this->mailer->sendNewRequest($contact->email, $contact->firstName, $artisan->userId, $summary, $direct);
        }
    }
}
