<?php

declare(strict_types=1);

namespace App\Moderation\Application\Report;

/** Réponse au signalant (E-27). */
interface ReportMailer
{
    public function reportAnswered(string $email, string $firstName, string $userId, string $subjectTitle, bool $actioned, ?string $response): void;
}
