<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Checkout;

use App\Billing\Application\Checkout\BillingDetails;
use App\Billing\Application\Checkout\BillingProfiles;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalBillingProfiles implements BillingProfiles
{
    public function __construct(private Connection $connection)
    {
    }

    public function find(string $artisanId): ?BillingDetails
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM billing.billing_profile WHERE artisan_id = :a', ['a' => $artisanId]);

        return false === $row ? null : new BillingDetails(
            DbalValue::string($row['company_name']),
            DbalValue::string($row['street']),
            DbalValue::string($row['postal_code']),
            DbalValue::string($row['city']),
            DbalValue::string($row['country']),
            DbalValue::nullableString($row['vat_number']),
        );
    }

    public function save(string $artisanId, BillingDetails $details, ?\DateTimeImmutable $vatVerifiedAt, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO billing.billing_profile (artisan_id, company_name, street, postal_code, city, country, vat_number, vat_verified_at, updated_at)
                VALUES (:a, :name, :street, :pc, :city, :country, :vat, :verified, :at)
                ON CONFLICT (artisan_id) DO UPDATE SET company_name = excluded.company_name, street = excluded.street, postal_code = excluded.postal_code,
                    city = excluded.city, country = excluded.country, vat_number = excluded.vat_number, vat_verified_at = excluded.vat_verified_at, updated_at = excluded.updated_at
                SQL,
            ['a' => $artisanId, 'name' => $details->companyName, 'street' => $details->street, 'pc' => $details->postalCode, 'city' => $details->city, 'country' => $details->country, 'vat' => $details->vatNumber, 'verified' => $vatVerifiedAt?->format(\DATE_ATOM), 'at' => $at->format(\DATE_ATOM)],
        );
    }
}
