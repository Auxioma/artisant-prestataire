<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Maintenance;

use App\QuoteRequest\Application\Draft\DraftStore;
use App\QuoteRequest\Application\Port\RequestMailer;
use App\QuoteRequest\Application\Port\RequestMaintenanceStore;
use App\QuoteRequest\Domain\Request\QuoteRequest;
use App\QuoteRequest\Domain\Request\QuoteRequestId;
use App\QuoteRequest\Domain\Request\QuoteRequestRepository;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Identity\UserContact;
use App\Shared\Application\Identity\UserContactDetails;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Application\Storage\FileShredder;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/**
 * RG-064 : échéance → « expirée » (E-19 « expirée », relance unique possible depuis « Mes demandes ») ; E-19 une fois par
 * échéance, `requests.expiry_warning_days` avant ; RG-060 : E-19c au compte client dont le brouillon dort depuis
 * `requests.draft_reminder_hours`, puis purge des brouillons expirés (pièces détruites).
 */
final readonly class RunRequestMaintenanceHandler implements CommandHandler
{
    /** Volume traité par passage (la tâche est horaire : le reliquat part au passage suivant). */
    private const int BATCH = 200;
    private const string SHRED_REASON = 'request_draft_expired';

    public function __construct(
        private RequestMaintenanceStore $store,
        private QuoteRequestRepository $requests,
        private DraftStore $drafts,
        private RequestMailer $mailer,
        private QueryBus $queries,
        private EventBus $events,
        private SettingsProvider $settings,
        private FileShredder $shredder,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(RunRequestMaintenance $command): void
    {
        $now = $this->clock->now();
        match ($command->task) {
            'expire' => $this->expire($now),
            'warn' => $this->warn($now),
            'remind' => $this->remind($now),
            'purge' => $this->purge($now),
            default => throw new \InvalidArgumentException('Tâche inconnue : '.$command->task),
        };
    }

    private function expire(\DateTimeImmutable $now): void
    {
        foreach ($this->store->dueForExpiry($now, self::BATCH) as $id) {
            $request = $this->requests->findForUpdate(new QuoteRequestId($id));
            if (null === $request || !$request->isDue($now)) {
                continue;
            }
            $request->expire($now);
            $this->events->publish(...$request->releaseEvents());
            $this->audit->record(new AuditRecord(id: $this->ids->next(), occurredAt: $now, actorType: 'system', actorId: null, action: 'request.expired', subjectType: 'quote_request', subjectId: $id, context: ['number' => $request->number(), 'quotes' => $request->activeQuotesCount()]));
            $contact = $this->contact($request->clientId());
            $notice = $this->store->notice($id);
            if (null !== $contact && null !== $notice) {
                $this->mailer->sendExpiry($contact->email, $contact->firstName, $notice, true, $request->relaunchCount() < QuoteRequest::RELAUNCH_MAX);
            }
        }
    }

    private function warn(\DateTimeImmutable $now): void
    {
        $until = $now->modify(\sprintf('+%d days', $this->settings->int(SettingKey::RequestsExpiryWarningDays)));
        foreach ($this->store->expiringSoon($now, $until, self::BATCH) as $notice) {
            if (!$this->store->markSent(RequestMaintenanceStore::EXPIRY_WARNING, $notice->requestId, $notice->expiresAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i'), $now)) {
                continue;
            }
            $contact = $this->contact($notice->clientId);
            if (null !== $contact) {
                $this->mailer->sendExpiry($contact->email, $contact->firstName, $notice, false, false);
            }
        }
    }

    private function remind(\DateTimeImmutable $now): void
    {
        $hours = $this->settings->int(SettingKey::RequestsDraftReminderHours);
        if ($hours <= 0) {
            return;
        }
        foreach ($this->store->remindableDrafts($now->modify(\sprintf('-%d hours', $hours)), $now, self::BATCH) as $draft) {
            $contact = $this->contact($draft->userId);
            if (null !== $contact && $this->store->markSent(RequestMaintenanceStore::DRAFT_REMINDER, $draft->draftId, 'j1', $now)) {
                $this->mailer->sendDraftReminder($contact->email, $contact->firstName, $draft->userId, $draft->jobName, $this->settings->int(SettingKey::RequestsDraftRetentionDays));
            }
        }
    }

    private function purge(\DateTimeImmutable $now): void
    {
        foreach ($this->store->expiredDrafts($now, self::BATCH) as $draft) {
            foreach ($draft->files as $fileId) {
                $this->shredder->shred($fileId, self::SHRED_REASON);
            }
            $this->drafts->delete($draft->draftId);
        }
    }

    private function contact(string $userId): ?UserContactDetails
    {
        $contact = $this->queries->ask(new UserContact($userId));

        return $contact instanceof UserContactDetails ? $contact : null;
    }
}
