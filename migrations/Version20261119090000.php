<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-501 — devis : en-tête (un par artisan et par demande, RG-101 d ; un seul accepté par demande, RG-072), versions
 * (RG-102, figées une fois envoyées) et lignes (montants en centimes, quantités en millièmes, taux en points de base).
 */
final class Version20261119090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Devis : quote.quote, quote.quote_version, quote.quote_line';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE quote.quote (
                id uuid PRIMARY KEY,
                number varchar(30) NULL,
                request_id uuid NOT NULL REFERENCES request.quote_request (id) ON DELETE CASCADE,
                artisan_id uuid NOT NULL REFERENCES artisan.artisan_profile (id),
                status varchar(20) NOT NULL CHECK (status IN ('draft', 'sent', 'viewed', 'accepted', 'refused', 'not_selected', 'expired', 'withdrawn', 'request_cancelled')),
                current_version integer NOT NULL CHECK (current_version >= 1),
                sent_at timestamptz NULL,
                viewed_at timestamptz NULL,
                decided_at timestamptz NULL,
                decision_reason varchar(500) NULL,
                valid_until timestamptz NULL,
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                version integer NOT NULL DEFAULT 1,
                CHECK (status = 'draft' OR (number IS NOT NULL AND sent_at IS NOT NULL AND valid_until IS NOT NULL)),
                CHECK (status <> 'refused' OR decision_reason IS NOT NULL),
                CHECK (status <> 'viewed' OR viewed_at IS NOT NULL)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX quote_request_artisan_uniq ON quote.quote (request_id, artisan_id)');
        $this->addSql("CREATE UNIQUE INDEX quote_accepted_uniq ON quote.quote (request_id) WHERE status = 'accepted'");
        $this->addSql('CREATE UNIQUE INDEX quote_number_uniq ON quote.quote (artisan_id, number) WHERE number IS NOT NULL');
        $this->addSql('CREATE INDEX quote_artisan_idx ON quote.quote (artisan_id, status, sent_at DESC)');
        $this->addSql("CREATE INDEX quote_valid_until_idx ON quote.quote (valid_until) WHERE status IN ('sent', 'viewed')");

        $this->addSql(<<<'SQL'
            CREATE TABLE quote.quote_version (
                id uuid PRIMARY KEY,
                quote_id uuid NOT NULL REFERENCES quote.quote (id) ON DELETE CASCADE,
                version integer NOT NULL CHECK (version >= 1),
                subject varchar(200) NOT NULL,
                gross_ht_cents bigint NOT NULL,
                discount_bp integer NOT NULL DEFAULT 0 CHECK (discount_bp BETWEEN 0 AND 10000),
                discount_cents bigint NOT NULL,
                total_ht_cents bigint NOT NULL,
                total_vat_cents bigint NOT NULL,
                total_ttc_cents bigint NOT NULL,
                vat_by_rate jsonb NOT NULL,
                vat_exempt boolean NOT NULL,
                deposit_percent_bp integer NULL CHECK (deposit_percent_bp BETWEEN 1 AND 10000),
                deposit_amount_cents bigint NULL CHECK (deposit_amount_cents > 0),
                deposit_cents bigint NULL,
                conditions jsonb NOT NULL,
                created_at timestamptz NOT NULL,
                sent_at timestamptz NULL,
                pdf_key varchar(200) NULL,
                pdf_sha256 char(64) NULL,
                CHECK (total_ttc_cents = total_ht_cents + total_vat_cents),
                CHECK (deposit_percent_bp IS NULL OR deposit_amount_cents IS NULL)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX quote_version_uniq ON quote.quote_version (quote_id, version)');

        $this->addSql(<<<'SQL'
            CREATE TABLE quote.quote_line (
                id uuid PRIMARY KEY,
                quote_version_id uuid NOT NULL REFERENCES quote.quote_version (id) ON DELETE CASCADE,
                position integer NOT NULL CHECK (position >= 1),
                type varchar(10) NOT NULL CHECK (type IN ('service', 'supply', 'labour', 'travel', 'section', 'comment')),
                label varchar(200) NOT NULL,
                description text NULL,
                quantity_milli bigint NOT NULL,
                unit varchar(20) NULL,
                unit_price_ht_cents bigint NOT NULL CHECK (unit_price_ht_cents >= 0),
                discount_bp integer NOT NULL CHECK (discount_bp BETWEEN 0 AND 10000),
                vat_rate_bp integer NOT NULL CHECK (vat_rate_bp IN (0, 550, 1000, 2000)),
                total_ht_cents bigint NOT NULL,
                CHECK (type IN ('section', 'comment') OR quantity_milli > 0)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX quote_line_position_uniq ON quote.quote_line (quote_version_id, position)');

        // RG-102 : une version envoyée est figée (ni modifiée, ni supprimée, ses lignes non plus).
        $this->addSql(<<<'SQL'
            CREATE FUNCTION quote.forbid_sent_version_change() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.sent_at IS NOT NULL AND pg_trigger_depth() = 1 THEN
                    RAISE EXCEPTION 'Version de devis envoyée : modification interdite';
                END IF;
                RETURN COALESCE(NEW, OLD);
            END $$
            SQL);
        $this->addSql('CREATE TRIGGER quote_version_frozen BEFORE UPDATE OR DELETE ON quote.quote_version FOR EACH ROW EXECUTE FUNCTION quote.forbid_sent_version_change()');
        $this->addSql(<<<'SQL'
            CREATE FUNCTION quote.forbid_sent_line_change() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF pg_trigger_depth() = 1 AND EXISTS (SELECT 1 FROM quote.quote_version v WHERE v.id = COALESCE(OLD.quote_version_id, NEW.quote_version_id) AND v.sent_at IS NOT NULL) THEN
                    RAISE EXCEPTION 'Ligne d''une version de devis envoyée : modification interdite';
                END IF;
                RETURN COALESCE(NEW, OLD);
            END $$
            SQL);
        $this->addSql('CREATE TRIGGER quote_line_frozen BEFORE INSERT OR UPDATE OR DELETE ON quote.quote_line FOR EACH ROW EXECUTE FUNCTION quote.forbid_sent_line_change()');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE quote.quote_line');
        $this->addSql('DROP TABLE quote.quote_version');
        $this->addSql('DROP TABLE quote.quote');
        $this->addSql('DROP FUNCTION quote.forbid_sent_line_change()');
        $this->addSql('DROP FUNCTION quote.forbid_sent_version_change()');
    }
}
