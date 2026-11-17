<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Query;

use App\Catalog\Application\Query\JobRequirement;
use App\Catalog\Application\Query\JobRequirements;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class DbalJobRequirementsHandler implements QueryHandler
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return list<JobRequirement> */
    public function __invoke(JobRequirements $query): array
    {
        $ids = array_values(array_filter($query->jobIds, static fn (string $id): bool => 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id)));
        if ([] === $ids) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, name, required_documents, insurance_required, decennial_required FROM catalog.job WHERE id IN (:ids) ORDER BY name',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );

        return array_map(static function (array $row): JobRequirement {
            $documents = DbalValue::json($row['required_documents']);

            return new JobRequirement(
                DbalValue::string($row['id']),
                DbalValue::string($row['name']),
                \is_array($documents) ? array_values(array_filter($documents, \is_string(...))) : [],
                true === $row['insurance_required'],
                true === $row['decennial_required'],
            );
        }, $rows);
    }
}
