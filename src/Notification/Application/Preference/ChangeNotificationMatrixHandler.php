<?php

declare(strict_types=1);

namespace App\Notification\Application\Preference;

use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Domain\Time\Clock;

final readonly class ChangeNotificationMatrixHandler implements CommandHandler
{
    public function __construct(private NotificationPreferences $preferences, private Clock $clock)
    {
    }

    public function __invoke(ChangeNotificationMatrix $command): void
    {
        $now = $this->clock->now();
        foreach (NotificationTopic::cases() as $topic) {
            foreach (NotificationTopic::CHANNELS as $channel) {
                $this->preferences->save($command->userId, $topic, $channel, (bool) ($command->matrix[$topic->value][$channel] ?? false), $now);
            }
        }
    }
}
