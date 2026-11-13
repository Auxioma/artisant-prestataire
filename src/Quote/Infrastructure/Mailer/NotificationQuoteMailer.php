<?php

declare(strict_types=1);

namespace App\Quote\Infrastructure\Mailer;

use App\Notification\Application\Notification;
use App\Notification\Application\Notifier;
use App\Quote\Application\Port\QuoteMailer;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** E-12 / E-12b / E-33 confiés au socle Notification ; liens vers C03 (T-507) ou, en attendant, « Mes demandes ». */
final readonly class NotificationQuoteMailer implements QuoteMailer
{
    public function __construct(private Notifier $notifier, private UrlGeneratorInterface $urls)
    {
    }

    public function sendNewQuote(string $email, string $firstName, string $userId, string $requestId, string $artisan, string $requestNumber, ?string $jobName, string $amountTtc, \DateTimeImmutable $validUntil): void
    {
        $link = $this->link('client_request', ['id' => $requestId], 'client_requests');
        $this->notifier->notify(new Notification('E-12', $email, $userId, [
            'prenom' => $firstName,
            'artisan' => $artisan,
            'numero' => $requestNumber,
            'metier' => $jobName,
            'montant' => $amountTtc,
            'validite' => $validUntil,
            'lien' => $link,
        ], $link));
    }

    public function sendRevisedQuote(string $email, string $firstName, string $userId, string $requestId, string $artisan, string $requestNumber, int $version, string $amountTtc, \DateTimeImmutable $validUntil): void
    {
        $link = $this->link('client_request', ['id' => $requestId], 'client_requests');
        $this->notifier->notify(new Notification('E-12b', $email, $userId, [
            'prenom' => $firstName,
            'artisan' => $artisan,
            'numero' => $requestNumber,
            'version' => $version,
            'montant' => $amountTtc,
            'validite' => $validUntil,
            'lien' => $link,
        ], $link));
    }

    public function notifyDraftHolder(string $userId, string $requestId, string $requestNumber, string $jobName, string $city): void
    {
        $this->notifier->notify(new Notification('E-33', null, $userId, ['numero' => $requestNumber, 'metier' => $jobName, 'ville' => $city], $this->link('pro_request', ['id' => $requestId], 'pro_requests')));
    }

    /** @param array<string, string> $parameters */
    private function link(string $route, array $parameters, string $fallback): string
    {
        try {
            return $this->urls->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (RouteNotFoundException) {
            return $this->urls->generate($fallback, [], UrlGeneratorInterface::ABSOLUTE_URL);
        }
    }
}
