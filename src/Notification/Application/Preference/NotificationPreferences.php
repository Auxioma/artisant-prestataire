<?php

declare(strict_types=1);

namespace App\Notification\Application\Preference;

/** Préférences d'envoi par sujet et canal ; sans choix enregistré, le sujet est envoyé. */
interface NotificationPreferences
{
    public const string EMAIL = 'email';

    public function enabled(string $userId, NotificationTopic $topic, string $channel): bool;

    /** @return array<string, bool> sujet → envoyé, pour un canal */
    public function forChannel(string $userId, string $channel): array;

    public function save(string $userId, NotificationTopic $topic, string $channel, bool $enabled, \DateTimeImmutable $at): void;
}
