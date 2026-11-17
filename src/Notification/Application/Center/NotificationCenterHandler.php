<?php

declare(strict_types=1);

namespace App\Notification\Application\Center;

use App\Notification\Application\Port\InAppNotifications;
use App\Shared\Application\Bus\QueryHandler;

/** C08 — page des notifications in-app (famille « Compte » = tous les codes hors familles explicites). */
final readonly class NotificationCenterHandler implements QueryHandler
{
    public function __construct(private InAppNotifications $notifications)
    {
    }

    public function __invoke(NotificationCenter $query): NotificationCenterView
    {
        $type = null === $query->type ? null : NotificationType::tryFrom($query->type);
        $page = max(1, $query->page);
        $result = $this->notifications->page($query->userId, $type?->codes(), NotificationType::Account === $type ? NotificationType::knownCodes() : [], NotificationCenter::PER_PAGE, ($page - 1) * NotificationCenter::PER_PAGE);

        return new NotificationCenterView(
            array_map(static fn (array $r): NotificationItem => new NotificationItem($r['id'], NotificationType::of($r['code']), $r['body'], $r['link'], $r['at'], $r['read']), $result['rows']),
            $result['total'],
            $this->notifications->unreadCount($query->userId),
            $page,
            NotificationCenter::PER_PAGE,
        );
    }
}
