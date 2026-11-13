<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Dispatch;

use App\QuoteRequest\Application\Port\DispatchTargets;
use App\QuoteRequest\Application\Port\RequestDispatches;
use App\QuoteRequest\Application\Port\RequestMailer;
use App\QuoteRequest\Domain\Request\QuoteRequestId;
use App\QuoteRequest\Domain\Request\QuoteRequestRepository;
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
 * destinataire : la demande reste publiée et l'équipe est alertée (BO04, journal d'audit).
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
            return; // en modération, refusée ou déjà close : rien à diffuser
        }
        $now = $this->clock->now();
        $target = $request->targetArtisanId();
        $sent = ['direct' => 0, 'immediate' => 0, 'digest' => 0];

        $direct = null === $target ? null : $this->targets->direct($target, $request->clientId());
        if (null !== $direct && $this->dispatches->record($command->requestId, $direct->artisanId, DispatchReason::Direct, $now, true)) {
            $this->alert($direct, $summary, true);
            ++$sent['direct'];
        }
        if (null === $target || $request->broadcastToOthers()) {
            foreach ($this->targets->eligible($request->jobId(), $request->cityInsee(), $request->clientId()) as $eligible) {
                $immediate = AlertFrequency::Immediate === $eligible->frequency;
                if ($eligible->artisanId === $target || !$this->dispatches->record($command->requestId, $eligible->artisanId, DispatchReason::Eligible, $now, $immediate)) {
                    continue;
                }
                if ($immediate) {
                    $this->alert($eligible, $summary, false);
                }
                ++$sent[$immediate ? 'immediate' : 'digest'];
            }
        }

        $total = $this->dispatches->countFor($command->requestId);
        $this->audit->record(new AuditRecord(
            id: $this->ids->next(),
            occurredAt: $now,
            actorType: 'system',
            actorId: null,
            action: 0 === $total ? 'request.dispatch_empty' : 'request.dispatched',
            subjectType: 'quote_request',
            subjectId: $command->requestId,
            context: $sent + ['total' => $total],
        ));
    }

    private function alert(DispatchTarget $artisan, DispatchSummary $summary, bool $direct): void
    {
        $contact = $this->queries->ask(new UserContact($artisan->userId));
        if ($contact instanceof UserContactDetails) {
            $this->mailer->sendNewRequest($contact->email, $contact->firstName, $artisan->userId, $summary, $direct);
        }
    }
}
