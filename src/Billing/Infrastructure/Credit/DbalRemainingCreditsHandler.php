<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Credit;

use App\Shared\Application\Billing\RemainingCredits;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class DbalRemainingCreditsHandler implements QueryHandler
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return array<string, int> */
    public function __invoke(RemainingCredits $query): array
    {
        $ids = array_values(array_filter($query->userIds, static fn (string $id): bool => 1 === preg_match('/^[0-9a-f-]{36}$/', $id)));
        if ([] === $ids) {
            return [];
        }
        $credits = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT a.user_id, b.free + b.plan + b.extra AS total FROM billing.credit_account a JOIN billing.credit_balance b ON b.artisan_id = a.artisan_id WHERE a.user_id IN (:ids)',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        ) as $row) {
            $credits[DbalValue::string($row['user_id'])] = DbalValue::int($row['total']);
        }

        return $credits;
    }
}
