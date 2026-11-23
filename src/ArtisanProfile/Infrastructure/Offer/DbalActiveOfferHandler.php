<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Infrastructure\Offer;

use App\ArtisanProfile\Application\Offer\ActiveOffer;
use App\ArtisanProfile\Application\Offer\ActiveOfferView;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalActiveOfferHandler implements QueryHandler
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(ActiveOffer $query): ?ActiveOfferView
    {
        if (1 !== preg_match(self::UUID, $query->offerId) || 1 !== preg_match(self::UUID, $query->artisanId)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(
            "SELECT id, title, type, value, ends_on FROM artisan.offer WHERE id = :id AND artisan_id = :a AND status = 'active' AND starts_on <= current_date AND ends_on >= current_date",
            ['id' => $query->offerId, 'a' => $query->artisanId],
        );

        return false === $row ? null : new ActiveOfferView(
            DbalValue::string($row['id']),
            DbalValue::string($row['title']),
            DbalValue::string($row['type']),
            is_numeric($row['value']) ? (float) $row['value'] : null,
            new \DateTimeImmutable(DbalValue::string($row['ends_on'])),
        );
    }
}
