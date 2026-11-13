<?php

declare(strict_types=1);

namespace App\QuoteRequest\Infrastructure\Mailer;

use App\Notification\Application\Notification;
use App\Notification\Application\Notifier;
use App\QuoteRequest\Application\Dispatch\DispatchSummary;
use App\QuoteRequest\Application\Maintenance\ExpiryNotice;
use App\QuoteRequest\Application\Port\RequestMailer;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** E-mails des demandes confiés au socle Notification ; liens absolus construits ici (DEFAULT_URI hors requête HTTP). */
final readonly class NotificationRequestMailer implements RequestMailer
{
    /** Demandes listées dans un digest ; au-delà, « et N autres » renvoie à PR02. */
    private const int DIGEST_LIST_MAX = 10;

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

    public function sendNewRequest(string $email, string $firstName, string $userId, DispatchSummary $request, bool $direct): void
    {
        $link = $this->proLink('pro_request', ['id' => $request->requestId]);
        $this->notifier->notify(new Notification($direct ? 'E-31' : 'E-30', $email, $userId, [
            'prenom' => $firstName,
            'digest' => false,
            'numero' => $request->number,
            'titre' => $request->title,
            'metier' => $request->jobName,
            'ville' => $request->city,
            'delai' => $request->timing,
            'budget' => $request->budget,
            'lien' => $link,
        ], $link));
    }

    public function sendDigest(string $email, string $firstName, string $userId, array $requests): void
    {
        $link = $this->proLink('pro_requests', []);
        $listed = \array_slice($requests, 0, self::DIGEST_LIST_MAX);
        $this->notifier->notify(new Notification('E-30', $email, $userId, [
            'prenom' => $firstName,
            'digest' => true,
            'nombre' => \count($requests),
            'liste' => implode("\n", array_map(static fn (DispatchSummary $r): string => '• '.$r->line(), $listed)),
            'autres' => \count($requests) - \count($listed),
            'lien' => $link,
        ], $link));
    }

    public function sendExpiry(string $email, string $firstName, ExpiryNotice $request, bool $expired, bool $canRelaunch): void
    {
        $link = $this->clientLink($request->requestId);
        $this->notifier->notify(new Notification('E-19', $email, $request->clientId, [
            'prenom' => $firstName,
            'expiree' => $expired,
            'relance_possible' => $canRelaunch,
            'numero' => $request->number,
            'metier' => $request->jobName,
            'ville' => $request->city,
            'date_expiration' => $request->expiresAt,
            'devis' => $request->receivedQuotes,
            'lien' => $link,
        ], $link));
    }

    public function sendDraftReminder(string $email, string $firstName, string $userId, ?string $jobName, int $retentionDays): void
    {
        $this->notifier->notify(new Notification('E-19c', $email, $userId, [
            'prenom' => $firstName,
            'metier' => $jobName,
            'conservation_jours' => $retentionDays,
            'lien' => $this->urls->generate('request_new', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ]));
    }

    /** C02 « Mes demandes » (T-406) ; tant qu'il n'est pas livré, la page de la demande envoyée. */
    private function clientLink(string $requestId): string
    {
        try {
            return $this->urls->generate('client_request', ['id' => $requestId], UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (RouteNotFoundException) {
            return $this->urls->generate('request_sent', ['id' => $requestId], UrlGeneratorInterface::ABSOLUTE_URL);
        }
    }

    /**
     * PR02 / PR03 (espace pro, T-405) ; tant qu'ils ne sont pas livrés, l'écran de suivi du compte pro.
     *
     * @param array<string, string> $parameters
     */
    private function proLink(string $route, array $parameters): string
    {
        try {
            return $this->urls->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (RouteNotFoundException) {
            return $this->urls->generate('artisan_signup_submitted', [], UrlGeneratorInterface::ABSOLUTE_URL);
        }
    }
}
