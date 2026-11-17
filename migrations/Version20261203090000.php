<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-705c1 — C07 « Mon profil » : photo, rayon de recherche et domaines d'intérêt du client ; préférences d'envoi des
 * notifications (sujet × canal, case cochée par défaut = aucune ligne).
 */
final class Version20261203090000 extends AbstractMigration
{
    private const string PURPOSES = "'artisan_document', 'artisan_logo', 'request_photo', 'message_attachment', 'quote_pdf', 'review_photo', 'portfolio_photo', 'contact_attachment'";

    public function getDescription(): string
    {
        return 'identity.client_profile (photo, rayon, intérêts), notification.preference';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shared.stored_file DROP CONSTRAINT stored_file_purpose_check');
        $this->addSql('ALTER TABLE shared.stored_file ADD CONSTRAINT stored_file_purpose_check CHECK (purpose IN ('.self::PURPOSES.", 'client_avatar'))");
        $this->addSql('ALTER TABLE identity.client_profile ADD avatar_file_id uuid NULL REFERENCES shared.stored_file (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE identity.client_profile ADD search_radius_km smallint NULL CHECK (search_radius_km > 0)');
        $this->addSql("ALTER TABLE identity.client_profile ADD interests jsonb NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(interests) = 'array')");
        $this->addSql(<<<'SQL'
            CREATE TABLE notification.preference (
                user_id uuid NOT NULL REFERENCES identity.user_account (id) ON DELETE CASCADE,
                topic varchar(30) NOT NULL,
                channel varchar(10) NOT NULL CHECK (channel IN ('email', 'in_app', 'sms')),
                enabled boolean NOT NULL,
                updated_at timestamptz NOT NULL,
                PRIMARY KEY (user_id, topic, channel)
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE notification.preference');
        $this->addSql('ALTER TABLE identity.client_profile DROP avatar_file_id, DROP search_radius_km, DROP interests');
        $this->addSql("DELETE FROM shared.stored_file WHERE purpose = 'client_avatar'");
        $this->addSql('ALTER TABLE shared.stored_file DROP CONSTRAINT stored_file_purpose_check');
        $this->addSql('ALTER TABLE shared.stored_file ADD CONSTRAINT stored_file_purpose_check CHECK (purpose IN ('.self::PURPOSES.'))');
    }
}
