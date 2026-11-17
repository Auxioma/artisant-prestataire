<?php

declare(strict_types=1);

namespace App\Notification\Application\Port;

/** Journal d'envoi (BO11.2). Aucune adresse en clair : empreinte et forme masquée. */
interface DeliveryLog
{
    public function record(string $id, string $code, int $templateVersion, string $channel, ?string $userId, ?string $email, string $status, ?string $providerMessageId, ?string $error, \DateTimeImmutable $at): void;
}
