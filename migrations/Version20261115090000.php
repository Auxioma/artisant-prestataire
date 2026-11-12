<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** T-307c — P14 : rubriques et questions d'aide (une question = une URL), votes « Cette réponse vous a-t-elle aidé ? ». */
final class Version20261115090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Aide / FAQ : content.faq_topic, content.faq_question, content.faq_vote';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE content.faq_topic (
                id uuid PRIMARY KEY,
                slug varchar(100) NOT NULL UNIQUE CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
                title varchar(120) NOT NULL,
                description varchar(300) NOT NULL,
                icon varchar(32) NOT NULL,
                position integer NOT NULL,
                active boolean NOT NULL DEFAULT true
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE content.faq_question (
                id uuid PRIMARY KEY,
                topic_id uuid NOT NULL REFERENCES content.faq_topic (id) ON DELETE CASCADE,
                slug varchar(120) NOT NULL CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
                question varchar(200) NOT NULL,
                answer_html text NOT NULL,
                audience varchar(10) NOT NULL CHECK (audience IN ('all', 'client', 'artisan')),
                position integer NOT NULL,
                published boolean NOT NULL DEFAULT true,
                helpful_yes integer NOT NULL DEFAULT 0 CHECK (helpful_yes >= 0),
                helpful_no integer NOT NULL DEFAULT 0 CHECK (helpful_no >= 0),
                updated_at timestamptz NOT NULL,
                UNIQUE (topic_id, slug)
            )
            SQL);
        $this->addSql('CREATE INDEX faq_question_topic_idx ON content.faq_question (topic_id, position) WHERE published');
        $this->addSql(<<<'SQL'
            CREATE TABLE content.faq_vote (
                question_id uuid NOT NULL REFERENCES content.faq_question (id) ON DELETE CASCADE,
                visitor_hash char(64) NOT NULL,
                helpful boolean NOT NULL,
                voted_at timestamptz NOT NULL,
                PRIMARY KEY (question_id, visitor_hash)
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE content.faq_vote');
        $this->addSql('DROP TABLE content.faq_question');
        $this->addSql('DROP TABLE content.faq_topic');
    }
}
