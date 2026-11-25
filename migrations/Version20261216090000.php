<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P09 « Offres & promotions » / PR10 / BO09 — offres publiées par les artisans. RG-026 : dates de début et de fin
 * obligatoires (l'offre disparaît à expiration), prix de référence obligatoire pour une réduction de prix (règle du
 * prix antérieur), modération avant publication (statut `pending` si moderation.offers_prior).
 */
final class Version20261216090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P09 — offres & promotions des artisans (artisan.offer)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE artisan.offer (
                id uuid PRIMARY KEY,
                artisan_id uuid NOT NULL REFERENCES artisan.artisan_profile(id) ON DELETE CASCADE,
                title varchar(120) NOT NULL CHECK (btrim(title) <> ''),
                description text NULL,
                type varchar(16) NOT NULL CHECK (type IN ('percent', 'amount', 'free')),
                value numeric(10, 2) NULL,
                reference_price_cents integer NULL CHECK (reference_price_cents IS NULL OR reference_price_cents > 0),
                job_ids uuid[] NOT NULL DEFAULT '{}',
                conditions varchar(200) NULL,
                photo_file_id uuid NULL REFERENCES shared.stored_file(id) ON DELETE SET NULL,
                starts_on date NOT NULL,
                ends_on date NOT NULL,
                status varchar(16) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'pending', 'active', 'refused', 'archived')),
                refusal_reason varchar(500) NULL,
                views integer NOT NULL DEFAULT 0,
                clicks integer NOT NULL DEFAULT 0,
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                CONSTRAINT offer_period CHECK (ends_on >= starts_on),
                CONSTRAINT offer_value CHECK (
                    (type = 'percent' AND value > 0 AND value < 100)
                    OR (type = 'amount' AND value > 0)
                    OR (type = 'free' AND (value IS NULL OR value >= 0))),
                CONSTRAINT offer_reference_price CHECK (type = 'free' OR reference_price_cents IS NOT NULL)
            )
            SQL);
        $this->addSql('CREATE INDEX offer_artisan_idx ON artisan.offer (artisan_id)');
        $this->addSql("CREATE INDEX offer_live_idx ON artisan.offer (ends_on) WHERE status = 'active'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE artisan.offer');
    }
}
