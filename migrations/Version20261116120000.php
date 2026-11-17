<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** T-401 — demande de devis : machine RG-066, max_quotes figé, plafond et anti-doublon RG-065, échéance RG-064. */
final class Version20261116120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Demandes : request.quote_request, numérotation lisible';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE request.quote_request_number_seq');
        $this->addSql(<<<'SQL'
            CREATE TABLE request.quote_request (
                id uuid PRIMARY KEY,
                number varchar(20) NOT NULL UNIQUE,
                client_id uuid NOT NULL REFERENCES identity.user_account (id),
                job_id uuid NOT NULL REFERENCES catalog.job (id),
                target_artisan_id uuid NULL REFERENCES artisan.artisan_profile (id),
                broadcast_to_others boolean NOT NULL,
                city_insee varchar(5) NOT NULL,
                postal_code varchar(5) NOT NULL,
                duplicate_key varchar(64) NOT NULL,
                status varchar(20) NOT NULL CHECK (status IN ('draft', 'pending_moderation', 'open', 'full', 'awarded', 'completed', 'expired', 'cancelled', 'rejected')),
                project_type varchar(20) NOT NULL CHECK (project_type IN ('repair', 'installation', 'renovation', 'maintenance', 'other')),
                description text NOT NULL CHECK (char_length(description) BETWEEN 30 AND 3000),
                answers jsonb NOT NULL,
                budget_range varchar(30) NULL,
                timing varchar(10) NOT NULL CHECK (timing IN ('urgent', 'week', 'month', 'flexible', 'date')),
                desired_date date NULL,
                max_quotes smallint NOT NULL CHECK (max_quotes >= 1),
                active_quotes_count smallint NOT NULL DEFAULT 0,
                received_quotes_count smallint NOT NULL DEFAULT 0,
                risk_score smallint NOT NULL CHECK (risk_score BETWEEN 0 AND 100),
                risk_signals jsonb NOT NULL,
                submitted_at timestamptz NOT NULL,
                published_at timestamptz NULL,
                expires_at timestamptz NULL,
                reopen_count smallint NOT NULL DEFAULT 0,
                relaunch_count smallint NOT NULL DEFAULT 0,
                status_reason varchar(500) NULL,
                status_changed_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                version integer NOT NULL DEFAULT 1,
                CONSTRAINT quote_request_slots_ck CHECK (active_quotes_count BETWEEN 0 AND max_quotes),
                CONSTRAINT quote_request_received_ck CHECK (received_quotes_count >= active_quotes_count),
                CONSTRAINT quote_request_date_ck CHECK ((timing = 'date') = (desired_date IS NOT NULL)),
                CONSTRAINT quote_request_published_ck CHECK (status IN ('draft', 'pending_moderation', 'rejected', 'cancelled') OR expires_at IS NOT NULL)
            )
            SQL);
        $this->addSql('CREATE INDEX quote_request_listing_idx ON request.quote_request (status, job_id, city_insee)');
        $this->addSql('CREATE INDEX quote_request_client_idx ON request.quote_request (client_id, status)');
        $this->addSql('CREATE INDEX quote_request_duplicate_idx ON request.quote_request (duplicate_key, submitted_at)');
        $this->addSql("CREATE INDEX quote_request_expiry_idx ON request.quote_request (expires_at) WHERE status IN ('open', 'full')");
        $this->addSql('CREATE INDEX quote_request_target_idx ON request.quote_request (target_artisan_id) WHERE target_artisan_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE request.quote_request');
        $this->addSql('DROP SEQUENCE request.quote_request_number_seq');
    }
}
