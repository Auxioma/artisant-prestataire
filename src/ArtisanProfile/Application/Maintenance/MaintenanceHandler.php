<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Maintenance;

use App\ArtisanProfile\Application\Port\MaintenanceCandidates;
use App\ArtisanProfile\Application\Port\ReminderLog;
use App\ArtisanProfile\Application\Port\SignupMailer;
use App\ArtisanProfile\Application\Signup\DocumentSlot;
use App\ArtisanProfile\Application\Signup\SignupRequirements;
use App\ArtisanProfile\Domain\Document\ArtisanDocument;
use App\ArtisanProfile\Domain\Document\ArtisanDocumentRepository;
use App\ArtisanProfile\Domain\Profile\ArtisanProfile;
use App\ArtisanProfile\Domain\Profile\ArtisanProfileId;
use App\ArtisanProfile\Domain\Profile\ArtisanProfileRepository;
use App\ArtisanProfile\Domain\Profile\ArtisanStatus;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Identity\UserContact;
use App\Shared\Application\Identity\UserContactDetails;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/**
 * Tâches quotidiennes du module (Scheduler) : échéances des justificatifs (RG-032, E-25), masquage des comptes non
 * assurés (RG-130), relances des dossiers incomplets (E-23). Chaque envoi est consigné : jamais deux fois la même étape.
 */
final readonly class MaintenanceHandler implements CommandHandler
{
    public const string DOCUMENT_EXPIRY = 'document_expiry';
    public const string SIGNUP_INCOMPLETE = 'signup_incomplete';
    private const string EXPIRED_STEP = 'expired';

    public function __construct(
        private MaintenanceCandidates $candidates,
        private ReminderLog $reminders,
        private ArtisanProfileRepository $profiles,
        private ArtisanDocumentRepository $documents,
        private SignupRequirements $requirements,
        private InsuranceCoverage $coverage,
        private SignupMailer $mailer,
        private QueryBus $queries,
        private SettingsProvider $settings,
        private EventBus $events,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(SendDocumentExpiryAlerts|HideUninsuredProfiles|SendSignupReminders $command): int
    {
        $today = $this->clock->now();

        return match (true) {
            $command instanceof SendDocumentExpiryAlerts => $this->expiryAlerts($today),
            $command instanceof HideUninsuredProfiles => $this->hideUninsured($today),
            default => $this->signupReminders($today),
        };
    }

    private function expiryAlerts(\DateTimeImmutable $today): int
    {
        $steps = $this->settings->intList(SettingKey::DocumentsExpiryAlertDays);
        if ([] === $steps) {
            return 0;
        }
        sort($steps);
        $sent = 0;
        foreach ($this->candidates->documentsExpiringBy($today->modify(\sprintf('+%d days', max($steps)))) as $documentId) {
            $document = $this->documents->find($documentId);
            $profile = null === $document ? null : $this->profile($document->artisanId());
            if (null === $document || null === $profile || null === $document->expiresOn()) {
                continue;
            }
            $days = (int) $today->setTime(0, 0)->diff($document->expiresOn()->setTime(0, 0))->format('%r%a');
            $expired = $document->expire($today);
            // Étape la plus urgente atteinte (une seule alerte même après une interruption de la tâche).
            $reached = array_values(array_filter($steps, static fn (int $s): bool => $days <= $s));
            $step = $days < 0 ? self::EXPIRED_STEP : (string) ($reached[0] ?? max($steps));
            if ([] === $reached && $days >= 0) {
                continue;
            }
            if ($this->reminders->markSent(self::DOCUMENT_EXPIRY, $document->id(), $step, $today) && null !== $contact = $this->contact($profile)) {
                $this->mailer->sendDocumentExpiry($contact->email, $contact->firstName, $this->label($profile, $document), $document->expiresOn(), max(0, $days), $expired || $days < 0, $profile->userId());
                ++$sent;
            }
        }

        return $sent;
    }

    private function hideUninsured(\DateTimeImmutable $today): int
    {
        $grace = $this->settings->int(SettingKey::DocumentsHideAfterExpiryDays);
        $limit = $today->modify(\sprintf('-%d days', $grace));
        $hidden = 0;
        foreach ($this->candidates->verifiedProfilesWithInsuranceEndingBefore($limit) as $profileId) {
            $profile = $this->profile($profileId);
            $lapsed = null === $profile ? null : $this->coverage->lapsedSince($profile, $today);
            if (null === $profile || null === $lapsed || $lapsed->format('Y-m-d') >= $limit->format('Y-m-d')) {
                continue;
            }
            $profile->hide(\sprintf('Assurance obligatoire expirée depuis le %s', $lapsed->format('d/m/Y')), $today);
            $this->events->publish(...$profile->releaseEvents());
            $this->audit->record(new AuditRecord(id: $this->ids->next(), occurredAt: $today, actorType: 'system', actorId: null, action: 'artisan.hidden', subjectType: 'artisan_profile', subjectId: $profileId, context: ['insurance_until' => $lapsed->format('Y-m-d'), 'grace_days' => $grace]));
            ++$hidden;
        }

        return $hidden;
    }

    private function signupReminders(\DateTimeImmutable $today): int
    {
        $steps = $this->settings->intList(SettingKey::ProSignupReminderDays);
        rsort($steps);
        $sent = 0;
        foreach ($this->candidates->unfinishedDossiers() as $profileId) {
            $profile = $this->profile($profileId);
            if (null === $profile) {
                continue;
            }
            $incomplete = ArtisanStatus::Incomplete === $profile->status();
            $missing = array_values(array_filter($this->requirements->checklist($profile, $this->documents->currentOf($profileId)), static fn (DocumentSlot $s): bool => $s->required && !$s->provided()));
            if (!$incomplete && [] === $missing) {
                continue; // dossier complet en attente de vérification : rien à relancer
            }
            $since = $incomplete ? $profile->createdAt() : ($profile->submittedAt() ?? $profile->createdAt());
            $elapsed = (int) $since->setTime(0, 0)->diff($today->setTime(0, 0))->format('%r%a');
            $step = array_values(array_filter($steps, static fn (int $s): bool => $elapsed >= $s))[0] ?? null;
            if (null === $step) {
                continue;
            }
            $key = ($incomplete ? 'incomplete:' : 'missing:').$step;
            if ($this->reminders->markSent(self::SIGNUP_INCOMPLETE, $profileId, $key, $today) && null !== $contact = $this->contact($profile)) {
                $this->mailer->sendIncompleteReminder($contact->email, $contact->firstName, $incomplete, array_map(static fn (DocumentSlot $s): string => $s->label, $missing), $profile->userId());
                ++$sent;
            }
        }

        return $sent;
    }

    private function profile(string $profileId): ?ArtisanProfile
    {
        return 1 === preg_match('/^[0-9a-f-]{36}$/', $profileId) ? $this->profiles->find(new ArtisanProfileId($profileId)) : null;
    }

    private function contact(ArtisanProfile $profile): ?UserContactDetails
    {
        $contact = $this->queries->ask(new UserContact($profile->userId()));

        return $contact instanceof UserContactDetails ? $contact : null;
    }

    private function label(ArtisanProfile $profile, ArtisanDocument $document): string
    {
        foreach ($this->requirements->checklist($profile, []) as $slot) {
            if ($slot->type === $document->type() && $slot->subject === $document->subject()) {
                return $slot->label;
            }
        }

        return 'Justificatif';
    }
}
