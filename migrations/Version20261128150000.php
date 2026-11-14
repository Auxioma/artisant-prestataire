<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-701d2 — BO05 « Messages signalés » : décision de la modération sur un message signalé (classé ou retiré de
 * l'affichage). Le texte reste conservé (preuve) : le déclencheur d'ajout seul n'autorise que ces colonnes.
 */
final class Version20261128150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'messaging.message : décision de modération';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE messaging.message
                ADD moderated_at timestamptz NULL,
                ADD moderated_by uuid NULL,
                ADD moderation_decision varchar(10) NULL CHECK (moderation_decision IN ('dismissed', 'removed')),
                ADD moderation_reason varchar(30) NULL,
                ADD CHECK ((moderated_at IS NULL) = (moderation_decision IS NULL)),
                ADD CHECK (moderation_decision IS NULL OR reported_at IS NOT NULL)
            SQL);
        $this->addSql('CREATE INDEX message_report_queue_idx ON messaging.message (reported_at) WHERE reported_at IS NOT NULL AND moderated_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX messaging.message_report_queue_idx');
        $this->addSql('ALTER TABLE messaging.message DROP moderated_at, DROP moderated_by, DROP moderation_decision, DROP moderation_reason');
    }
}
