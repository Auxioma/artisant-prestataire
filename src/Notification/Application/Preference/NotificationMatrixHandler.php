<?php

declare(strict_types=1);

namespace App\Notification\Application\Preference;

use App\Shared\Application\Bus\QueryHandler;

final readonly class NotificationMatrixHandler implements QueryHandler
{
    public function __construct(private NotificationPreferences $preferences)
    {
    }

    /** @return array<string, array<string, bool>> */
    public function __invoke(NotificationMatrix $query): array
    {
        $matrix = [];
        foreach (NotificationTopic::CHANNELS as $channel) {
            $saved = $this->preferences->forChannel($query->userId, $channel);
            foreach (NotificationTopic::cases() as $topic) {
                $matrix[$topic->value][$channel] = $saved[$topic->value] ?? true;
            }
        }

        return $matrix;
    }
}
