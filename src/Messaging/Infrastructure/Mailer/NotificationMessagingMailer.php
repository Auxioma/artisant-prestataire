<?php

declare(strict_types=1);

namespace App\Messaging\Infrastructure\Mailer;

use App\Messaging\Application\Port\MessagingMailer;
use App\Notification\Application\Notification;
use App\Notification\Application\Notifier;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Identity\UserContact;
use App\Shared\Application\Identity\UserContactDetails;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** E-17 confié au socle Notification ; lien vers le fil dans l'espace du destinataire. */
final readonly class NotificationMessagingMailer implements MessagingMailer
{
    public function __construct(private Notifier $notifier, private QueryBus $queries, private UrlGeneratorInterface $urls)
    {
    }

    public function newMessage(string $recipientUserId, bool $recipientIsArtisan, string $senderName, string $excerpt, string $conversationId): void
    {
        $contact = $this->queries->ask(new UserContact($recipientUserId));
        if (!$contact instanceof UserContactDetails) {
            return;
        }
        $link = $this->link($recipientIsArtisan ? 'pro_conversation' : 'client_conversation', $recipientIsArtisan ? 'pro_dashboard' : 'client_dashboard', $conversationId);
        $this->notifier->notify(new Notification('E-17', $contact->email, $recipientUserId, [
            'prenom' => $contact->firstName,
            'expediteur' => $senderName,
            'extrait' => $excerpt,
            'lien' => $link,
        ], $link));
    }

    private function link(string $route, string $fallback, string $conversationId): string
    {
        try {
            return $this->urls->generate($route, ['id' => $conversationId], UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (RouteNotFoundException) {
            return $this->urls->generate($fallback, [], UrlGeneratorInterface::ABSOLUTE_URL);
        }
    }
}
