<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Dispatch;

use App\QuoteRequest\Application\Port\RequestDispatches;
use App\QuoteRequest\Domain\Request\QuoteRequestId;
use App\QuoteRequest\Domain\Request\QuoteRequestRepository;
use App\QuoteRequest\Domain\Request\RequestStatus;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/**
 * P-09 — demande redevenue ouverte après un retrait : ses destinataires (sauf exclus et artisans l'ayant ignorée) la
 * retrouvent dans leur prochain digest. Sans effet si la demande n'est plus ouverte.
 */
final readonly class RenotifyRequestHandler implements CommandHandler
{
    public function __construct(private QuoteRequestRepository $requests, private RequestDispatches $dispatches, private AuditTrail $audit, private IdGenerator $ids, private Clock $clock)
    {
    }

    public function __invoke(RenotifyRequest $command): void
    {
        $request = $this->requests->find(new QuoteRequestId($command->requestId));
        if (null === $request || RequestStatus::Open !== $request->status()) {
            return;
        }
        $count = $this->dispatches->renotify($command->requestId, $command->excludeArtisanIds);
        $now = $this->clock->now();
        $this->audit->record(new AuditRecord(
            id: $this->ids->next(),
            occurredAt: $now,
            actorType: 'system',
            actorId: null,
            action: 'request.renotified',
            subjectType: 'quote_request',
            subjectId: $command->requestId,
            context: ['artisans' => $count, 'reason' => 'slot_freed'],
        ));
    }
}
