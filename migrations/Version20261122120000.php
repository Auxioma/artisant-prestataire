<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** T-603b — informations de facturation de l'artisan (PR12.2), reprises à chaque souscription. */
final class Version20261122120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'billing.billing_profile';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE billing.billing_profile (
                artisan_id uuid PRIMARY KEY REFERENCES billing.credit_account (artisan_id),
                company_name varchar(120) NOT NULL,
                street varchar(200) NOT NULL,
                postal_code varchar(10) NOT NULL,
                city varchar(100) NOT NULL,
                country char(2) NOT NULL CHECK (country ~ '^[A-Z]{2}$'),
                vat_number varchar(20) NULL,
                vat_verified_at timestamptz NULL,
                updated_at timestamptz NOT NULL,
                CHECK (vat_verified_at IS NULL OR vat_number IS NOT NULL)
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE billing.billing_profile');
    }
}
