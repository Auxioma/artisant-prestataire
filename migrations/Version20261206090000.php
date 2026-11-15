<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-706b — BO05.3 « Seuils anti-spam » : un message dont le score de risque atteint `moderation.message_risk_threshold`
 * rejoint « Messages signalés » sans signalant (détection automatique).
 */
final class Version20261206090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'messaging.message : signalement automatique (signalant facultatif)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE messaging.message DROP CONSTRAINT message_check');
        $this->addSql('ALTER TABLE messaging.message ADD CONSTRAINT message_reporter_check CHECK (reported_by IS NULL OR reported_at IS NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE messaging.message SET reported_at = NULL, report_reason = NULL WHERE reported_by IS NULL AND moderated_at IS NULL');
        $this->addSql('ALTER TABLE messaging.message DROP CONSTRAINT message_reporter_check');
        $this->addSql('ALTER TABLE messaging.message ADD CONSTRAINT message_check CHECK ((reported_at IS NULL) = (reported_by IS NULL))');
    }
}
