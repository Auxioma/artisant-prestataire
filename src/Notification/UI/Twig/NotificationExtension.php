<?php

declare(strict_types=1);

namespace App\Notification\UI\Twig;

use App\Notification\Application\Query\UnreadInAppCount;
use App\Shared\Application\Bus\QueryBus;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** tm_unread_notifications(userId) : compteur de la cloche de l'en-tête (notifications in-app non lues). */
final class NotificationExtension extends AbstractExtension
{
    public function __construct(private readonly QueryBus $queries)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('tm_unread_notifications', $this->unread(...))];
    }

    public function unread(?string $userId): int
    {
        if (null === $userId) {
            return 0;
        }
        $count = $this->queries->ask(new UnreadInAppCount($userId));

        return \is_int($count) ? $count : 0;
    }
}
