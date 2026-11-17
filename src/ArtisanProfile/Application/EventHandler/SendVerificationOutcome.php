<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\EventHandler;

use App\ArtisanProfile\Application\Port\SignupMailer;
use App\ArtisanProfile\Application\Signup\SignupRequirements;
use App\ArtisanProfile\Domain\Document\ArtisanDocumentRepository;
use App\ArtisanProfile\Domain\Document\Event\ArtisanDocumentReviewed;
use App\ArtisanProfile\Domain\Profile\ArtisanProfile;
use App\ArtisanProfile\Domain\Profile\ArtisanProfileId;
use App\ArtisanProfile\Domain\Profile\ArtisanProfileRepository;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanMoreInfoRequested;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanReinstated;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanRejected;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanSuspended;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanVerified;
use App\ArtisanProfile\Domain\Profile\RejectionReason;
use App\Shared\Application\Bus\EventHandler;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Identity\UserContact;
use App\Shared\Application\Identity\UserContactDetails;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;

/** Décisions BO03 notifiées à l'artisan : E-21 (vérifié), E-22 (refus ou complément), E-24 (pièce contrôlée), E-26 (suspension, réactivation). */
final readonly class SendVerificationOutcome implements EventHandler
{
    public function __construct(
        private ArtisanProfileRepository $profiles,
        private ArtisanDocumentRepository $documents,
        private SignupRequirements $requirements,
        private QueryBus $queries,
        private SignupMailer $mailer,
        private SettingsProvider $settings,
    ) {
    }

    public function __invoke(ArtisanVerified|ArtisanRejected|ArtisanMoreInfoRequested|ArtisanDocumentReviewed|ArtisanSuspended|ArtisanReinstated $event): void
    {
        $profile = $this->profiles->find(new ArtisanProfileId($event->profileId));
        $contact = null === $profile ? null : $this->queries->ask(new UserContact($profile->userId()));
        if (null === $profile || !$contact instanceof UserContactDetails) {
            return;
        }
        $company = $profile->tradeName() ?? (string) $profile->companyName();

        match (true) {
            $event instanceof ArtisanVerified => $this->mailer->sendVerified($contact->email, $contact->firstName, $company, $this->settings->int(SettingKey::CreditsFreeOnSignup), $profile->userId()),
            $event instanceof ArtisanRejected => $this->mailer->sendRejectedOrMoreInfo($contact->email, $contact->firstName, $company, true, RejectionReason::tryFrom($event->reason)?->label(), $event->comment, $profile->userId()),
            $event instanceof ArtisanSuspended => $this->mailer->sendAccountStatus($contact->email, $contact->firstName, true, $event->reason, $profile->userId()),
            $event instanceof ArtisanReinstated => $this->mailer->sendAccountStatus($contact->email, $contact->firstName, false, null, $profile->userId()),
            $event instanceof ArtisanMoreInfoRequested => $this->mailer->sendRejectedOrMoreInfo($contact->email, $contact->firstName, $company, false, null, $event->message, $profile->userId()),
            default => $this->mailer->sendDocumentReviewed($contact->email, $contact->firstName, $this->documentLabel($profile, $event), 'approved' === $event->status, $event->reason, $profile->userId()),
        };
    }

    private function documentLabel(ArtisanProfile $profile, ArtisanDocumentReviewed $event): string
    {
        foreach ($this->requirements->checklist($profile, $this->documents->currentOf($profile->id()->value)) as $slot) {
            if ($slot->type === $event->type && $slot->subject === $event->subject) {
                return $slot->label;
            }
        }

        return 'Justificatif';
    }
}
