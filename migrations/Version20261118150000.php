<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-404a — diffusion RG-062 : artisans notifiés par demande (`request_dispatch`, base de PR02), blocages posés par le
 * client (`client_artisan_block`) et préférences de réception de l'artisan (PR14.2 : fréquence des alertes, pause).
 */
final class Version20261118150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Diffusion des demandes : request_dispatch, client_artisan_block, artisan.request_preference';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE request.request_dispatch (
                request_id uuid NOT NULL REFERENCES request.quote_request (id) ON DELETE CASCADE,
                artisan_id uuid NOT NULL REFERENCES artisan.artisan_profile (id) ON DELETE CASCADE,
                reason varchar(10) NOT NULL CHECK (reason IN ('direct', 'eligible', 'extended')),
                dispatched_at timestamptz NOT NULL,
                notified_at timestamptz NULL,
                viewed_at timestamptz NULL,
                ignored_at timestamptz NULL,
                PRIMARY KEY (request_id, artisan_id),
                CHECK (notified_at IS NULL OR notified_at >= dispatched_at)
            )
            SQL);
        $this->addSql('CREATE INDEX request_dispatch_artisan_idx ON request.request_dispatch (artisan_id, dispatched_at DESC)');
        // Alertes en attente du prochain digest (E-30 regroupé).
        $this->addSql('CREATE INDEX request_dispatch_digest_idx ON request.request_dispatch (artisan_id) WHERE notified_at IS NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE request.client_artisan_block (
                client_id uuid NOT NULL REFERENCES identity.user_account (id) ON DELETE CASCADE,
                artisan_id uuid NOT NULL REFERENCES artisan.artisan_profile (id) ON DELETE CASCADE,
                created_at timestamptz NOT NULL,
                PRIMARY KEY (client_id, artisan_id)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE artisan.request_preference (
                artisan_id uuid PRIMARY KEY REFERENCES artisan.artisan_profile (id) ON DELETE CASCADE,
                alert_frequency varchar(20) NOT NULL DEFAULT 'immediate' CHECK (alert_frequency IN ('immediate', 'twice_daily', 'daily')),
                paused_at timestamptz NULL,
                updated_at timestamptz NOT NULL
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE artisan.request_preference');
        $this->addSql('DROP TABLE request.client_artisan_block');
        $this->addSql('DROP TABLE request.request_dispatch');
    }
}
