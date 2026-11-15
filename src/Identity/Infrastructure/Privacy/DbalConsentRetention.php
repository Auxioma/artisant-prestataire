<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Privacy;

use App\Identity\Application\Privacy\ConsentRetention;
use Doctrine\DBAL\Connection;

final readonly class DbalConsentRetention implements ConsentRetention
{
    public function __construct(private Connection $connection)
    {
    }

    public function purgeExpired(\DateTimeImmutable $now): int
    {
        return (int) $this->connection->executeStatement('DELETE FROM identity.consent WHERE expires_at < :now', ['now' => $now->format(\DATE_ATOM)]);
    }
}
