<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P15 / BO10.3 — blog : catégories ordonnées, articles (texte riche assaini, image et auteur de la médiathèque, métiers
 * liés pour l'encart « Trouvez un {métier} », article à la une) ; brouillon (published_at NULL), programmé (date future)
 * ou publié.
 */
final class Version20261217090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'content.blog_category, content.blog_article';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE content.blog_category (
                id uuid PRIMARY KEY,
                slug varchar(80) NOT NULL UNIQUE CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
                name varchar(60) NOT NULL,
                position smallint NOT NULL DEFAULT 0,
                created_at timestamptz NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE content.blog_article (
                id uuid PRIMARY KEY,
                slug varchar(100) NOT NULL UNIQUE CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
                title varchar(120) NOT NULL,
                short_title varchar(40) NULL,
                excerpt varchar(300) NULL,
                category_id uuid NULL REFERENCES content.blog_category (id) ON DELETE SET NULL,
                author_name varchar(80) NULL,
                author_media_id uuid NULL REFERENCES content.media (id) ON DELETE SET NULL,
                cover_media_id uuid NULL REFERENCES content.media (id) ON DELETE SET NULL,
                content_html text NOT NULL DEFAULT '',
                job_ids uuid[] NOT NULL DEFAULT '{}',
                featured boolean NOT NULL DEFAULT false,
                meta_title varchar(70) NULL,
                meta_description varchar(170) NULL,
                published_at timestamptz NULL,
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                updated_by uuid NULL
            )
            SQL);
        $this->addSql('CREATE INDEX blog_article_published_idx ON content.blog_article (published_at DESC) WHERE published_at IS NOT NULL');
        $this->addSql('CREATE INDEX blog_article_category_idx ON content.blog_article (category_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE content.blog_article');
        $this->addSql('DROP TABLE content.blog_category');
    }
}
