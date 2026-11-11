<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\EventHandler;

use App\ArtisanProfile\Application\Port\SignupMailer;
use App\ArtisanProfile\Domain\Profile\ArtisanProfileRepository;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanSignupSubmitted;
use App\Shared\Application\Bus\EventHandler;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Identity\UserContact;
use App\Shared\Application\Identity\UserContactDetails;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;

/** E-20 : accusé de dépôt du dossier (délai annoncé, crédits offerts à la vérification — RG-044). */
final readonly class SendSignupReceived implements EventHandler
{
    public function __construct(
        private ArtisanProfileRepository $profiles,
        private QueryBus $queries,
        private SignupMailer $mailer,
        private SettingsProvider $settings,
    ) {
    }

    public function __invoke(ArtisanSignupSubmitted $event): void
    {
        $contact = $this->queries->ask(new UserContact($event->userId));
        $profile = $this->profiles->findByUserId($event->userId);
        if (!$contact instanceof UserContactDetails || null === $profile) {
            return;
        }
        $this->mailer->sendSignupReceived(
            $contact->email,
            $contact->firstName,
            $profile->tradeName() ?? $profile->companyName() ?? '',
            $this->settings->int(SettingKey::ProVerificationDelayHours),
            $this->settings->int(SettingKey::CreditsFreeOnSignup),
            $event->userId,
        );
    }
}
