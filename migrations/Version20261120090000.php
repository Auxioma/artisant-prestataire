<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-601 — forfaits BO08.1 (`billing.plan`) et prix versionnés (`billing.plan_price` : un nouveau montant crée un nouveau
 * prix, l'ancien reste pour l'historique et les abonnés existants). Q5 : Essentiel, Pro, Premium seedés en brouillon,
 * **sans prix** ni plafond d'usage (valeurs à fixer par le porteur en BO08).
 */
final class Version20261120090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'billing.plan, billing.plan_price ; forfaits Q5 en brouillon sans prix';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE billing.plan (
                id uuid PRIMARY KEY,
                slug varchar(60) NOT NULL UNIQUE,
                name varchar(60) NOT NULL,
                description text,
                quotes_per_period integer CHECK (quotes_per_period > 0),
                unlimited boolean NOT NULL DEFAULT false,
                fair_use_cap integer CHECK (fair_use_cap > 0),
                features jsonb NOT NULL DEFAULT '[]',
                max_offers integer CHECK (max_offers >= 0),
                featured boolean NOT NULL DEFAULT false,
                advanced_stats boolean NOT NULL DEFAULT false,
                badge varchar(30),
                position integer NOT NULL DEFAULT 0,
                visibility varchar(10) NOT NULL CHECK (visibility IN ('public', 'private')),
                access_code varchar(40) UNIQUE,
                status varchar(10) NOT NULL CHECK (status IN ('draft', 'published', 'archived')),
                ever_published boolean NOT NULL DEFAULT false,
                published_at timestamptz,
                stripe_product_id varchar(100),
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                version integer NOT NULL DEFAULT 1,
                CHECK (NOT (unlimited AND quotes_per_period IS NOT NULL)),
                CHECK (fair_use_cap IS NULL OR unlimited),
                CHECK ((visibility = 'private') = (access_code IS NOT NULL))
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE billing.plan_price (
                id uuid PRIMARY KEY,
                plan_id uuid NOT NULL REFERENCES billing.plan (id) ON DELETE CASCADE,
                interval varchar(5) NOT NULL CHECK (interval IN ('month', 'year')),
                amount_cents integer NOT NULL CHECK (amount_cents >= 0),
                currency char(3) NOT NULL DEFAULT 'EUR',
                stripe_price_id varchar(100),
                active boolean NOT NULL DEFAULT true,
                created_at timestamptz NOT NULL,
                deactivated_at timestamptz
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX plan_price_active_uniq ON billing.plan_price (plan_id, interval) WHERE active');
        foreach ([
            ['0190f5e4-0000-7000-8000-00000000b801', 'essentiel', 'Essentiel', 30, 'false', 1],
            ['0190f5e4-0000-7000-8000-00000000b802', 'pro', 'Pro', 80, 'false', 2],
            ['0190f5e4-0000-7000-8000-00000000b803', 'premium', 'Premium', null, 'true', 3],
        ] as [$id, $slug, $name, $quotes, $unlimited, $position]) {
            $this->addSql(
                "INSERT INTO billing.plan (id, slug, name, quotes_per_period, unlimited, position, visibility, status, created_at, updated_at) VALUES (:id, :slug, :name, :quotes, :unlimited, :position, 'public', 'draft', now(), now())",
                ['id' => $id, 'slug' => $slug, 'name' => $name, 'quotes' => $quotes, 'unlimited' => $unlimited, 'position' => $position],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE billing.plan_price');
        $this->addSql('DROP TABLE billing.plan');
    }
}
