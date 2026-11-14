<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-606a — codes promo BO08.3 (§4.3) : remise en % ou en montant, durée, forfaits éligibles, validité, utilisations
 * maximales (globales et par compte), réservé aux nouveaux abonnés ; synchronisés avec Stripe (Coupon + Promotion
 * Code). Une utilisation est enregistrée au paiement de la souscription (une par abonnement).
 */
final class Version20261125090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'billing.promo_code, billing.promo_redemption';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE billing.promo_code (
                id uuid PRIMARY KEY,
                code varchar(30) NOT NULL UNIQUE CHECK (code ~ '^[A-Z0-9_-]{3,30}$'),
                discount_type varchar(10) NOT NULL CHECK (discount_type IN ('percent', 'amount')),
                percent_bp integer NULL CHECK (percent_bp BETWEEN 1 AND 10000),
                amount_cents integer NULL CHECK (amount_cents > 0),
                duration varchar(10) NOT NULL CHECK (duration IN ('once', 'repeating', 'forever')),
                duration_months smallint NULL CHECK (duration_months BETWEEN 1 AND 36),
                plan_ids uuid[] NOT NULL DEFAULT '{}',
                valid_until timestamptz NULL,
                max_redemptions integer NULL CHECK (max_redemptions > 0),
                max_per_account integer NULL CHECK (max_per_account > 0),
                new_subscribers_only boolean NOT NULL DEFAULT false,
                status varchar(10) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'disabled')),
                stripe_coupon_id varchar(255) NOT NULL,
                stripe_promotion_code_id varchar(255) NOT NULL UNIQUE,
                created_by uuid NOT NULL,
                created_at timestamptz NOT NULL,
                disabled_at timestamptz NULL,
                CHECK ((discount_type = 'percent') = (percent_bp IS NOT NULL) AND (discount_type = 'amount') = (amount_cents IS NOT NULL)),
                CHECK ((duration = 'repeating') = (duration_months IS NOT NULL))
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE billing.promo_redemption (
                promo_code_id uuid NOT NULL REFERENCES billing.promo_code (id),
                subscription_id uuid NOT NULL REFERENCES billing.subscription (id),
                artisan_id uuid NOT NULL REFERENCES billing.credit_account (artisan_id),
                redeemed_at timestamptz NOT NULL,
                PRIMARY KEY (promo_code_id, subscription_id)
            )
            SQL);
        $this->addSql('CREATE INDEX promo_redemption_artisan_idx ON billing.promo_redemption (promo_code_id, artisan_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE billing.promo_redemption');
        $this->addSql('DROP TABLE billing.promo_code');
    }
}
