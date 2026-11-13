<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** T-504 — numérotation des devis : séquence annuelle par artisan (PR04.1, « DEV-2026-0042 »), attribuée à l'envoi. */
final class Version20261119120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'quote.quote_number_sequence';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE quote.quote_number_sequence (
                artisan_id uuid NOT NULL REFERENCES artisan.artisan_profile (id) ON DELETE CASCADE,
                year integer NOT NULL CHECK (year BETWEEN 2000 AND 2999),
                last integer NOT NULL CHECK (last >= 1),
                PRIMARY KEY (artisan_id, year)
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE quote.quote_number_sequence');
    }
}
