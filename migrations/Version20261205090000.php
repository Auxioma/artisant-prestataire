<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-706a — BO05.2 « Signalements » : avis, profil ou demande signalés par un utilisateur connecté ; instantané du contenu
 * au moment du signalement (preuve), décision de l'équipe et réponse au signalant.
 */
final class Version20261205090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'moderation.report';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE moderation.report (
                id uuid PRIMARY KEY,
                subject_type varchar(10) NOT NULL CHECK (subject_type IN ('avis', 'profil', 'demande')),
                subject_id uuid NOT NULL,
                subject_title varchar(200) NOT NULL,
                subject_excerpt text NOT NULL,
                subject_owner_id uuid NULL,
                reporter_id uuid NULL REFERENCES identity.user_account (id) ON DELETE SET NULL,
                reason varchar(30) NOT NULL,
                details varchar(1000) NULL,
                status varchar(10) NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'dismissed', 'actioned')),
                decided_by uuid NULL,
                decided_at timestamptz NULL,
                decision_note varchar(1000) NULL,
                created_at timestamptz NOT NULL,
                CHECK ((status = 'open') = (decided_at IS NULL))
            )
            SQL);
        $this->addSql("CREATE INDEX report_open_idx ON moderation.report (created_at) WHERE status = 'open'");
        $this->addSql("CREATE UNIQUE INDEX report_once_uniq ON moderation.report (reporter_id, subject_type, subject_id) WHERE status = 'open'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE moderation.report');
    }
}
