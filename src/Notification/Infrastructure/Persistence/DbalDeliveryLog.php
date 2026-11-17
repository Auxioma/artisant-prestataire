<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Persistence;

use App\Notification\Application\Port\DeliveryLog;
use Doctrine\DBAL\Connection;

final readonly class DbalDeliveryLog implements DeliveryLog
{
    public function __construct(private Connection $connection)
    {
    }

    public function record(string $id, string $code, int $templateVersion, string $channel, ?string $userId, ?string $email, string $status, ?string $providerMessageId, ?string $error, \DateTimeImmutable $at): void
    {
        $this->connection->insert('notification.delivery', [
            'id' => $id,
            'code' => $code,
            'template_version' => $templateVersion,
            'channel' => $channel,
            'user_id' => $userId,
            'recipient_hash' => null === $email ? null : hash('sha256', mb_strtolower(trim($email))),
            'recipient_masked' => null === $email ? null : self::mask($email),
            'status' => $status,
            'provider_message_id' => null === $providerMessageId ? null : mb_substr($providerMessageId, 0, 200),
            'error' => $error,
            'created_at' => $at->format(\DATE_ATOM),
            'updated_at' => $at->format(\DATE_ATOM),
        ]);
    }

    /** « camille@exemple.test » → « c***@exemple.test » : lisible par le support, inexploitable en masse. */
    public static function mask(string $email): string
    {
        $at = strrpos($email, '@');
        if (false === $at) {
            return '***';
        }

        return mb_substr($email, 0, 1).'***'.mb_substr($email, $at, 76);
    }
}
