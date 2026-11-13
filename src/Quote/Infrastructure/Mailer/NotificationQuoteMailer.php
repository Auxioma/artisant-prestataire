<?php

declare(strict_types=1);

namespace App\Quote\Infrastructure\Mailer;

use App\Notification\Application\Notification;
use App\Notification\Application\Notifier;
use App\Quote\Application\Port\QuoteMailer;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** E-12 (et sa relance P-11) / E-12b / E-13 / E-33 / E-34 / E-35 / E-35b confiés au socle Notification ; liens vers C03 (T-507) ou, en attendant, « Mes demandes ». */
final readonly class NotificationQuoteMailer implements QuoteMailer
{
    public function __construct(private Notifier $notifier, private UrlGeneratorInterface $urls)
    {
    }

    public function sendNewQuote(string $email, string $firstName, string $userId, string $requestId, string $artisan, string $requestNumber, ?string $jobName, string $amountTtc, \DateTimeImmutable $validUntil, bool $reminder = false): void
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
            'rappel' => $reminder,
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

    public function quoteViewed(string $artisanUserId, string $requestId, string $quoteNumber, string $requestNumber): void
    {
        $this->notifier->notify(new Notification('E-35b', null, $artisanUserId, ['numero' => $quoteNumber, 'demande' => $requestNumber], $this->link('pro_quote_new', ['demande' => $requestId], 'pro_requests')));
    }

    public function quoteAccepted(string $email, string $firstName, string $artisanUserId, string $requestId, string $quoteNumber, string $requestNumber, string $amountTtc, array $client): void
    {
        $link = $this->link('pro_request', ['id' => $requestId], 'pro_requests');
        $this->notifier->notify(new Notification('E-34', $email, $artisanUserId, [
            'prenom' => $firstName,
            'numero' => $quoteNumber,
            'demande' => $requestNumber,
            'montant' => $amountTtc,
            'client' => $client['name'],
            'client_email' => $client['email'],
            'client_telephone' => $client['phone'],
            'adresse' => $client['address'],
            'lien' => $link,
        ], $link));
    }

    public function acceptanceSummary(string $email, string $firstName, string $clientUserId, string $requestId, string $quoteNumber, string $requestNumber, string $amountTtc, array $artisan): void
    {
        $link = $this->link('client_request', ['id' => $requestId], 'client_requests');
        $this->notifier->notify(new Notification('E-13', $email, $clientUserId, [
            'prenom' => $firstName,
            'numero' => $quoteNumber,
            'demande' => $requestNumber,
            'montant' => $amountTtc,
            'artisan' => $artisan['name'],
            'artisan_email' => $artisan['email'],
            'artisan_telephone' => $artisan['phone'],
            'lien' => $link,
        ], $link));
    }

    public function quoteDeclined(string $email, string $firstName, string $artisanUserId, string $requestId, string $quoteNumber, string $requestNumber, bool $refused, ?string $reason): void
    {
        $link = $this->link('pro_quote_new', ['demande' => $requestId], 'pro_requests');
        $this->notifier->notify(new Notification('E-35', $email, $artisanUserId, [
            'prenom' => $firstName,
            'numero' => $quoteNumber,
            'demande' => $requestNumber,
            'refuse' => $refused,
            'motif' => $reason,
            'lien' => $link,
        ], $link));
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
