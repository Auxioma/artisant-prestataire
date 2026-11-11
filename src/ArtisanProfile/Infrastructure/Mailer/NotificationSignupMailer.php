<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Infrastructure\Mailer;

use App\ArtisanProfile\Application\Port\SignupMailer;
use App\Notification\Application\Notification;
use App\Notification\Application\Notifier;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** E-mails du tunnel artisan confiés au socle Notification ; liens absolus construits ici (DEFAULT_URI hors requête HTTP). */
final readonly class NotificationSignupMailer implements SignupMailer
{
    public function __construct(private Notifier $notifier, private UrlGeneratorInterface $urls)
    {
    }

    public function sendSignupReceived(string $email, string $firstName, string $companyName, int $delayHours, int $freeCredits, string $userId): void
    {
        $this->notifier->notify(new Notification('E-20', $email, $userId, [
            'prenom' => $firstName,
            'entreprise' => $companyName,
            'delai_heures' => $delayHours,
            'devis_offerts' => $freeCredits,
            'lien' => $this->urls->generate('artisan_signup_submitted', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ]));
    }
}
