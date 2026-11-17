<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Consent;

use App\Identity\Application\Port\ConsentLog;
use App\Identity\Domain\Consent\CookieConsent;
use Doctrine\DBAL\Connection;

final readonly class DbalConsentLog implements ConsentLog
{
    public function __construct(private Connection $connection)
    {
    }

    public function record(string $id, string $visitorId, ?string $userId, CookieConsent $consent, ?string $ipHash): void
    {
        $this->connection->insert('identity.consent', [
            'id' => $id,
            'visitor_id' => $visitorId,
            'user_id' => $userId,
            'choices' => json_encode($consent->choices, \JSON_THROW_ON_ERROR),
            'source' => $consent->source->value,
            'policy_version' => $consent->policyVersion,
            'ip_hash' => $ipHash,
            'given_at' => $consent->givenAt->format('Y-m-d H:i:s.uP'),
            'expires_at' => $consent->expiresAt->format('Y-m-d H:i:s.uP'),
        ]);
    }
}
