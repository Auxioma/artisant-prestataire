<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\EventHandler;

use App\Catalog\Domain\Event\JobProposalDecided;
use App\QuoteRequest\Application\Risk\RequestRiskSignal;
use App\QuoteRequest\Domain\Request\QuoteRequestRepository;
use App\QuoteRequest\Domain\Request\RequestStatus;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Application\Bus\EventHandler;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Time\Clock;

/**
 * BO06 — décision du référentiel sur un métier proposé par un client : les demandes qui l'attendaient sont rattachées au
 * métier retenu puis publiées (E-10 « demande publiée », diffusion aux professionnels) ; refus → demandes refusées avec
 * le motif (E-10b). Une demande portant d'autres signaux de risque reste dans la file de modération.
 */
final readonly class ReleaseRequestsOnJobProposalDecided implements EventHandler
{
    public function __construct(private QuoteRequestRepository $requests, private SettingsProvider $settings, private EventBus $events, private Clock $clock)
    {
    }

    public function __invoke(JobProposalDecided $event): void
    {
        $now = $this->clock->now();
        foreach ($this->requests->pendingOnJob($event->proposedJobId) as $id) {
            $request = $this->requests->findForUpdate($id);
            if (null === $request || RequestStatus::PendingModeration !== $request->status()) {
                continue;
            }
            if (null === $event->finalJobId) {
                $request->reject(\sprintf('Prestation « %s » non retenue : %s', $event->proposedName, $event->note ?? 'non proposée sur la plateforme'), $now);
            } else {
                if ($event->finalJobId !== $request->jobId()) {
                    $request->reassignJob($event->finalJobId, $now);
                }
                $others = array_diff($request->riskSignals(), [RequestRiskSignal::JobProposal->value]);
                if ([] === $others) {
                    $request->approve($this->settings->int(SettingKey::RequestsExpiryDays), $now);
                }
            }
            $this->events->publish(...$request->releaseEvents());
        }
    }
}
