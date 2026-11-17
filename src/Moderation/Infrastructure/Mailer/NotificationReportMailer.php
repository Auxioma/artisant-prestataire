<?php

declare(strict_types=1);

namespace App\Moderation\Infrastructure\Mailer;

use App\Moderation\Application\Report\ReportMailer;
use App\Notification\Application\Notification;
use App\Notification\Application\Notifier;

final readonly class NotificationReportMailer implements ReportMailer
{
    public function __construct(private Notifier $notifier)
    {
    }

    public function reportAnswered(string $email, string $firstName, string $userId, string $subjectTitle, bool $actioned, ?string $response): void
    {
        $this->notifier->notify(new Notification('E-27', $email, $userId, [
            'prenom' => $firstName,
            'contenu' => $subjectTitle,
            'suite_donnee' => $actioned,
            'reponse' => $response,
        ]));
    }
}
