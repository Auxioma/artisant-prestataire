<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Query;

use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Application\Identity\AccountReview;
use App\Shared\Application\Identity\AccountReviewDetails;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

/** BO03 — contrôles automatiques : doublons de téléphone et d'appareil (comptes non anonymisés). */
final readonly class DbalAccountReviewHandler implements QueryHandler
{
    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(AccountReview $query): ?AccountReviewDetails
    {
        if (1 !== preg_match('/^[0-9a-f-]{36}$/', $query->userId)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT u.first_name, u.last_name, u.email, u.email_verified_at IS NOT NULL AS verified,
                       (SELECT count(*) FROM identity.user_account o WHERE u.phone IS NOT NULL AND o.phone = u.phone AND o.id <> u.id AND o.status <> 'anonymized') AS same_phone,
                       (SELECT count(DISTINCT d2.user_id) FROM identity.session_device d1
                          JOIN identity.session_device d2 ON d2.device_hash = d1.device_hash AND d2.user_id <> d1.user_id
                         WHERE d1.user_id = u.id) AS same_device
                FROM identity.user_account u
                WHERE u.id = :id AND u.status <> 'anonymized'
                SQL,
            ['id' => $query->userId],
        );
        if (false === $row) {
            return null;
        }

        return new AccountReviewDetails(
            DbalValue::string($row['first_name']),
            DbalValue::string($row['last_name']),
            DbalValue::string($row['email']),
            true === $row['verified'],
            DbalValue::int($row['same_phone']),
            DbalValue::int($row['same_device']),
        );
    }
}
