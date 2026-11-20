<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-801e — BO10.6 libellés : dictionnaire des textes d'interface (clé, langue, texte d'origine relevé dans les
 * gabarits, texte modifié par l'équipe). Prépare le multilingue (une ligne par clé et par langue).
 */
final class Version20261210090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'content.ui_label';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE content.ui_label (
                key varchar(120) NOT NULL CHECK (key ~ '^[a-z0-9_]+(\.[a-z0-9_]+)+$'),
                locale varchar(5) NOT NULL DEFAULT 'fr' CHECK (locale ~ '^[a-z]{2}(_[A-Z]{2})?$'),
                default_text varchar(500) NOT NULL,
                text varchar(500) NULL CHECK (text IS NULL OR char_length(btrim(text)) > 0),
                updated_at timestamptz NOT NULL,
                updated_by uuid NULL,
                PRIMARY KEY (key, locale)
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE content.ui_label');
    }
}
