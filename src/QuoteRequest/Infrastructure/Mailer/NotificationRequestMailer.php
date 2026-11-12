<?php

declare(strict_types=1);

namespace App\QuoteRequest\Infrastructure\Mailer;

use App\Notification\Application\Notification;
use App\Notification\Application\Notifier;
use App\QuoteRequest\Application\Port\RequestMailer;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** E-mails des demandes confiés au socle Notification ; liens absolus construits ici (DEFAULT_URI hors requête HTTP). */
final readonly class NotificationRequestMailer implements RequestMailer
{
    public function __construct(private Notifier $notifier, private UrlGeneratorInterface $urls)
    {
    }

    public function sendSubmitted(string $email, string $firstName, string $userId, string $requestId, string $number, string $jobName, string $city, int $maxQuotes): void
    {
        $link = $this->urls->generate('request_sent', ['id' => $requestId], UrlGeneratorInterface::ABSOLUTE_URL);
        $this->notifier->notify(new Notification('E-10', $email, $userId, [
            'prenom' => $firstName,
            'numero' => $number,
            'metier' => $jobName,
            'ville' => $city,
            'max_devis' => $maxQuotes,
            'lien' => $link,
        ], $link));
    }

    public function sendModeration(string $email, string $firstName, string $userId, string $number, string $jobName, ?string $refusalReason): void
    {
        $this->notifier->notify(new Notification('E-10b', $email, $userId, [
            'prenom' => $firstName,
            'numero' => $number,
            'metier' => $jobName,
            'refusee' => null !== $refusalReason,
            'motif' => $refusalReason,
            'lien_aide' => $this->urls->generate('content_contact', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ]));
    }
}
