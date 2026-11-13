<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-505 — alertes de crédits RG-105 (E-42, E-42b) : une alerte par seuil franchi, jamais deux fois. `kind` : crédit
 * gratuit (`free`) ; le forfait (`plan`, période de facturation dans `period`) viendra avec le lot 6.
 */
final class Version20261119150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'billing.credit_alert';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE billing.credit_alert (
                artisan_id uuid NOT NULL REFERENCES billing.credit_account (artisan_id) ON DELETE CASCADE,
                kind text NOT NULL CHECK (kind IN ('free', 'plan')),
                period text NOT NULL DEFAULT '',
                threshold integer NOT NULL CHECK (threshold BETWEEN 1 AND 100),
                sent_at timestamptz NOT NULL,
                PRIMARY KEY (artisan_id, kind, period, threshold)
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE billing.credit_alert');
    }
}
