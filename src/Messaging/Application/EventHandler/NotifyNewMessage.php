<?php

declare(strict_types=1);

namespace App\Messaging\Application\EventHandler;

use App\Messaging\Application\Port\MessagingDirectory;
use App\Messaging\Application\Port\MessagingMailer;
use App\Messaging\Application\Port\ParticipantStates;
use App\Messaging\Application\Query\ConversationReader;
use App\Messaging\Domain\Conversation\Event\MessagePosted;
use App\Shared\Application\Bus\EventHandler;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Time\Clock;

/**
 * E-17 — nouveau message, regroupé : au plus un e-mail par fil toutes les `notifications.message_email_throttle_min`
 * minutes, et rien si le destinataire a déjà lu le fil (il est en ligne).
 */
final readonly class NotifyNewMessage implements EventHandler
{
    private const int EXCERPT = 140;

    public function __construct(
        private ParticipantStates $participants,
        private ConversationReader $reader,
        private MessagingDirectory $directory,
        private MessagingMailer $mailer,
        private SettingsProvider $settings,
        private Clock $clock,
    ) {
    }

    public function __invoke(MessagePosted $event): void
    {
        $now = $this->clock->now();
        $throttle = $this->settings->int(SettingKey::NotificationsMessageEmailThrottleMin);
        if (!$this->participants->claimNotification($event->conversationId, $event->recipientUserId, $event->at, $now->modify(\sprintf('-%d minutes', $throttle)), $now)) {
            return;
        }
        $message = $this->reader->message($event->messageId);
        if (null === $message) {
            return;
        }
        $senderArtisan = $this->directory->artisanOfUser($event->senderUserId);
        $excerpt = mb_strlen($message['body']) > self::EXCERPT ? rtrim(mb_substr($message['body'], 0, self::EXCERPT)).'…' : $message['body'];
        $this->mailer->newMessage($event->recipientUserId, null === $senderArtisan, $senderArtisan['name'] ?? $message['sender_first_name'], $excerpt, $event->conversationId);
    }
}
