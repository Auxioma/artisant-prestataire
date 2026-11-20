<?php

declare(strict_types=1);

namespace App\Review\Infrastructure\Mailer;

use App\Notification\Application\Notification;
use App\Notification\Application\Notifier;
use App\Review\Application\Port\ReviewMailer;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class NotificationReviewMailer implements ReviewMailer
{
    private const array DECISIONS = [
        'published' => 'est publié',
        'rejected' => 'n’a pas été publié',
        'hidden' => 'a été masqué par la modération',
        'changes_requested' => 'doit être modifié avant publication',
    ];

    public function __construct(private Notifier $notifier, private UrlGeneratorInterface $urls)
    {
    }

    public function reviewDecision(string $email, ?string $firstName, string $userId, string $decision, string $artisanName, ?string $reason): void
    {
        $link = $this->link('client_reviews');
        $this->notifier->notify(new Notification('E-18', $email, $userId, [
            'prenom' => $firstName ?? '',
            'artisan' => $artisanName,
            'decision' => $decision,
            'resultat' => self::DECISIONS[$decision] ?? 'a été examiné',
            'motif' => $reason ?? '',
            'lien' => $link,
        ], $link));
    }

    public function newReview(string $email, ?string $firstName, string $userId, int $rating, string $requestNumber): void
    {
        $link = $this->link('pro_reviews');
        $this->notifier->notify(new Notification('E-37', $email, $userId, [
            'prenom' => $firstName ?? '',
            'note' => (string) $rating,
            'demande' => $requestNumber,
            'lien' => $link,
        ], $link));
    }

    public function replyDecision(string $userId, bool $published, ?string $reason): void
    {
        $link = $this->link('pro_reviews');
        $this->notifier->notify(new Notification('E-38', null, $userId, [
            'publiee' => $published ? 'oui' : 'non',
            'motif' => $reason ?? '',
            'lien' => $link,
        ], $link));
    }

    public function invitation(string $email, ?string $firstName, string $userId, string $artisanName, string $requestId, bool $reminder): void
    {
        $link = $this->link('client_review_new', ['demande' => $requestId]);
        $this->notifier->notify(new Notification('E-16', $email, $userId, [
            'prenom' => $firstName ?? '',
            'artisan' => $artisanName,
            'relance' => $reminder ? 'oui' : 'non',
            'lien' => $link,
        ], $link));
    }

    /** @param array<string, string> $params */
    private function link(string $route, array $params = []): string
    {
        try {
            return $this->urls->generate($route, $params, UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (RouteNotFoundException) {
            return $this->urls->generate('public_home', [], UrlGeneratorInterface::ABSOLUTE_URL);
        }
    }
}
