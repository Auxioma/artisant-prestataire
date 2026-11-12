<?php

declare(strict_types=1);

namespace App\QuoteRequest\Infrastructure\Persistence;

use App\QuoteRequest\Application\Port\RequestContentStore;
use App\Shared\Application\Security\SecretBox;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalRequestContentStore implements RequestContentStore
{
    public function __construct(private Connection $connection, private SecretBox $box)
    {
    }

    public function saveAddress(string $requestId, string $label, ?float $latitude, ?float $longitude, \DateTimeImmutable $now): void
    {
        $geocoded = null !== $latitude && null !== $longitude;
        $this->connection->executeStatement(
            'INSERT INTO request.request_address (request_id, address_sealed, location, geocoded, created_at) VALUES (:id, :sealed, '
            .($geocoded ? 'ST_SetSRID(ST_MakePoint(:lon, :lat), 4326)::geography' : 'NULL').', :geocoded, :now)',
            ['id' => $requestId, 'sealed' => $this->box->seal($label), 'geocoded' => $geocoded, 'now' => $now->format(\DATE_ATOM)] + ($geocoded ? ['lat' => $latitude, 'lon' => $longitude] : []),
            ['geocoded' => 'boolean'],
        );
    }

    public function attachFiles(string $requestId, array $files): void
    {
        foreach (array_values($files) as $position => $file) {
            $this->connection->insert('request.request_attachment', ['request_id' => $requestId, 'file_id' => $file['file_id'], 'original_name' => mb_substr($file['name'], 0, 120), 'position' => $position + 1]);
        }
    }

    public function address(string $requestId): ?string
    {
        $sealed = $this->connection->fetchOne('SELECT address_sealed FROM request.request_address WHERE request_id = :id', ['id' => $requestId]);

        return false === $sealed ? null : $this->box->open(DbalValue::string($sealed));
    }
}
