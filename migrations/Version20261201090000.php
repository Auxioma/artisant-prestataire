<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-704a — avis certifiés (C06, PR09, P10.9, BO05.1) : un avis par demande (RG-033), note 1–5 et sous-notes, texte
 * 50–2 000, modération (RG-035) et score de risque, réponse modérée de l'artisan (RG-036), signalement.
 */
final class Version20261201090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'review.review';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SCHEMA IF NOT EXISTS review');
        $this->addSql(<<<'SQL'
            CREATE TABLE review.review (
                id uuid PRIMARY KEY,
                request_id uuid NOT NULL UNIQUE REFERENCES request.quote_request (id) ON DELETE CASCADE,
                quote_id uuid NOT NULL REFERENCES quote.quote (id) ON DELETE CASCADE,
                client_user_id uuid NOT NULL,
                artisan_id uuid NOT NULL REFERENCES artisan.artisan_profile (id) ON DELETE CASCADE,
                rating smallint NOT NULL CHECK (rating BETWEEN 1 AND 5),
                sub_ratings jsonb NOT NULL DEFAULT '{}',
                title varchar(100) NULL,
                body text NOT NULL CHECK (char_length(body) BETWEEN 50 AND 2000),
                photos jsonb NOT NULL DEFAULT '[]',
                service_date date NOT NULL,
                recommends boolean NULL,
                status varchar(20) NOT NULL CHECK (status IN ('pending', 'published', 'rejected', 'hidden', 'changes_requested')),
                risk_score smallint NOT NULL DEFAULT 0 CHECK (risk_score BETWEEN 0 AND 100),
                reason_code varchar(30) NULL,
                moderator_note varchar(500) NULL,
                moderated_by uuid NULL,
                moderated_at timestamptz NULL,
                reply_body varchar(1500) NULL,
                reply_status varchar(20) NULL CHECK (reply_status IN ('pending', 'published', 'rejected')),
                reply_at timestamptz NULL,
                reply_reason varchar(30) NULL,
                reported_at timestamptz NULL,
                report_reason varchar(300) NULL,
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                CHECK ((reply_body IS NULL) = (reply_status IS NULL)),
                CHECK ((reported_at IS NULL) = (report_reason IS NULL))
            )
            SQL);
        $this->addSql('CREATE INDEX review_artisan_idx ON review.review (artisan_id, status, created_at DESC)');
        $this->addSql("CREATE INDEX review_queue_idx ON review.review (created_at) WHERE status = 'pending' OR reply_status = 'pending' OR reported_at IS NOT NULL");
        $this->addSql('CREATE INDEX review_client_idx ON review.review (client_user_id, created_at DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE review.review');
    }
}
