<?php

declare(strict_types=1);

namespace App\Notification\Application\Preference;

use App\Shared\Application\Bus\QueryHandler;

final readonly class EmailPreferencesHandler implements QueryHandler
{
    public function __construct(private NotificationPreferences $preferences)
    {
    }

    /** @return array<string, bool> */
    public function __invoke(EmailPreferences $query): array
    {
        $saved = $this->preferences->forChannel($query->userId, NotificationPreferences::EMAIL);
        $all = [];
        foreach (NotificationTopic::cases() as $topic) {
            $all[$topic->value] = $saved[$topic->value] ?? true;
        }

        return $all;
    }
}
