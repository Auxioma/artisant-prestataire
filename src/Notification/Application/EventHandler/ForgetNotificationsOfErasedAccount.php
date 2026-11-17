<?php

declare(strict_types=1);

namespace App\Notification\Application\EventHandler;

use App\Notification\Application\Port\InAppNotifications;
use App\Notification\Application\Preference\NotificationPreferences;
use App\Shared\Application\Bus\EventHandler;
use App\Shared\Domain\Event\AccountErased;

/** RG-090 — compte effacé : notifications du centre C08 et préférences supprimées (le journal d'envoi est masqué). */
final readonly class ForgetNotificationsOfErasedAccount implements EventHandler
{
    public function __construct(private InAppNotifications $inApp, private NotificationPreferences $preferences)
    {
    }

    public function __invoke(AccountErased $event): void
    {
        $this->inApp->forget($event->userId);
        $this->preferences->forget($event->userId);
    }
}
