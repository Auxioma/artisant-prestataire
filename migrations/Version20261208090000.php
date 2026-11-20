<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-801b — BO10.2 pages à blocs : brouillon, version en ligne, version programmée (visible dès son heure), méta SEO.
 */
final class Version20261208090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'content.page';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE content.page (
                id uuid PRIMARY KEY,
                slug varchar(80) NOT NULL UNIQUE CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
                title varchar(120) NOT NULL,
                meta_title varchar(70) NULL,
                meta_description varchar(170) NULL,
                noindex boolean NOT NULL DEFAULT false,
                draft jsonb NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(draft) = 'array'),
                live jsonb NULL CHECK (live IS NULL OR jsonb_typeof(live) = 'array'),
                live_at timestamptz NULL,
                pending jsonb NULL CHECK (pending IS NULL OR jsonb_typeof(pending) = 'array'),
                pending_at timestamptz NULL,
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                updated_by uuid NULL,
                CHECK ((live IS NULL) = (live_at IS NULL)),
                CHECK ((pending IS NULL) = (pending_at IS NULL))
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE content.page');
    }
}
