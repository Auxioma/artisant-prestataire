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

    public function sendVerified(string $email, string $firstName, string $companyName, int $freeCredits, string $userId): void
    {
        $this->notify('E-21', $email, $userId, ['prenom' => $firstName, 'entreprise' => $companyName, 'devis_offerts' => $freeCredits]);
    }

    public function sendRejectedOrMoreInfo(string $email, string $firstName, string $companyName, bool $refused, ?string $reasonLabel, ?string $message, string $userId): void
    {
        $this->notify('E-22', $email, $userId, ['prenom' => $firstName, 'entreprise' => $companyName, 'refus' => $refused, 'motif' => $reasonLabel, 'message' => $message]);
    }

    public function sendDocumentReviewed(string $email, string $firstName, string $documentLabel, bool $approved, ?string $reason, string $userId): void
    {
        $this->notify('E-24', $email, $userId, ['prenom' => $firstName, 'piece' => $documentLabel, 'valide' => $approved, 'motif' => $reason]);
    }

    public function sendIncompleteReminder(string $email, string $firstName, bool $incomplete, array $missing, string $userId): void
    {
        $link = $this->urls->generate($incomplete ? 'artisan_signup_resume' : 'artisan_signup_documents', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $this->notifier->notify(new Notification('E-23', $email, $userId, ['prenom' => $firstName, 'inacheve' => $incomplete, 'pieces' => implode(', ', $missing), 'lien' => $link]));
    }

    public function sendDocumentExpiry(string $email, string $firstName, string $documentLabel, \DateTimeImmutable $expiresOn, int $daysLeft, bool $expired, string $userId): void
    {
        $link = $this->urls->generate('artisan_signup_documents', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $this->notifier->notify(new Notification('E-25', $email, $userId, ['prenom' => $firstName, 'piece' => $documentLabel, 'date_expiration' => $expiresOn, 'jours' => $daysLeft, 'expire' => $expired, 'lien' => $link], $link));
    }

    /** @param array<string, scalar|null> $variables */
    private function notify(string $code, string $email, string $userId, array $variables): void
    {
        $link = $this->urls->generate('artisan_signup_submitted', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $this->notifier->notify(new Notification($code, $email, $userId, $variables + ['lien' => $link], $link));
    }
}
