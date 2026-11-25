<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Newsletter;

use App\Content\Application\Port\NewsletterSubscribers;
use Doctrine\DBAL\Connection;

final readonly class DbalNewsletterSubscribers implements NewsletterSubscribers
{
    public function __construct(private Connection $connection)
    {
    }

    public function subscribe(string $id, string $email, string $token, string $source, ?string $ipHash, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(
            'INSERT INTO content.newsletter_subscriber (id, email, token, source, ip_hash, subscribed_at)
             VALUES (:id, :email, :token, :source, :ip, :at)
             ON CONFLICT (lower(email)) DO UPDATE SET unsubscribed_at = NULL, subscribed_at = EXCLUDED.subscribed_at, source = EXCLUDED.source, ip_hash = EXCLUDED.ip_hash
             WHERE content.newsletter_subscriber.unsubscribed_at IS NOT NULL',
            ['id' => $id, 'email' => $email, 'token' => $token, 'source' => $source, 'ip' => $ipHash, 'at' => $at->format(\DATE_ATOM)],
        );
    }

    public function unsubscribe(string $token, \DateTimeImmutable $at): bool
    {
        return 1 === $this->connection->executeStatement(
            'UPDATE content.newsletter_subscriber SET unsubscribed_at = coalesce(unsubscribed_at, :at) WHERE token = :token',
            ['token' => $token, 'at' => $at->format(\DATE_ATOM)],
        );
    }
}
