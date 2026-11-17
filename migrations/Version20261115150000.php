<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** T-309a — PR08 : présentation publique, prestations, horaires ; échantillon de modération humaine (RG-031). */
final class Version20261115150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Profil public : presentation, services, opening_hours ; artisan.profile_review';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE artisan.artisan_profile ADD presentation jsonb NOT NULL DEFAULT '{}'::jsonb, ADD services jsonb NOT NULL DEFAULT '[]'::jsonb, ADD opening_hours jsonb NOT NULL DEFAULT '{}'::jsonb");
        // RG-031 : contenus retenus pour une relecture humaine aléatoire (traitée dans la file de modération, BO05).
        $this->addSql(<<<'SQL'
            CREATE TABLE artisan.profile_review (
                id uuid PRIMARY KEY,
                artisan_id uuid NOT NULL REFERENCES artisan.artisan_profile (id) ON DELETE CASCADE,
                section varchar(30) NOT NULL,
                content jsonb NOT NULL,
                status varchar(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected')),
                created_at timestamptz NOT NULL
            )
            SQL);
        $this->addSql("CREATE INDEX profile_review_pending_idx ON artisan.profile_review (created_at) WHERE status = 'pending'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE artisan.profile_review');
        $this->addSql('ALTER TABLE artisan.artisan_profile DROP presentation, DROP services, DROP opening_hours');
    }
}
