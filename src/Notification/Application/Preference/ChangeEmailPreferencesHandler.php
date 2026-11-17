<?php

declare(strict_types=1);

namespace App\Notification\Application\Preference;

use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Domain\Time\Clock;

final readonly class ChangeEmailPreferencesHandler implements CommandHandler
{
    public function __construct(private NotificationPreferences $preferences, private Clock $clock)
    {
    }

    public function __invoke(ChangeEmailPreferences $command): void
    {
        $now = $this->clock->now();
        foreach (NotificationTopic::profileTopics() as $topic) {
            if (\array_key_exists($topic->value, $command->topics)) {
                $this->preferences->save($command->userId, $topic, NotificationPreferences::EMAIL, $command->topics[$topic->value], $now);
            }
        }
    }
}
