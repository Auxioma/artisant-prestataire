<?php

declare(strict_types=1);

namespace App\Administration\Infrastructure\Mailer;

use App\Administration\Application\Port\AdminMailer;
use App\Notification\Application\Notification;
use App\Notification\Application\Notifier;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** I-02 confié au socle Notification ; lien absolu vers le back-office (DEFAULT_URI ou hôte admin). */
final readonly class NotificationAdminMailer implements AdminMailer
{
    public function __construct(private Notifier $notifier, private UrlGeneratorInterface $urls)
    {
    }

    public function sendInvitation(string $email, string $name, string $roleLabel, #[\SensitiveParameter] string $token, int $ttlHours, string $adminId): void
    {
        $this->notifier->notify(new Notification('I-02', $email, null, [
            'nom' => $name,
            'role' => $roleLabel,
            'lien' => $this->urls->generate('admin_invitation', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL),
            'validite_heures' => $ttlHours,
        ]));
    }
}
