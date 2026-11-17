<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-116 — fichiers stockés (ADR-0008) : registre commun à tous les usages (justificatifs, photos, pièces jointes,
 * PDF de devis). Les tables métier (artisan.document, request_attachment…) référenceront `stored_file.id`.
 */
final class Version20261111210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Registre des fichiers stockés (shared.stored_file)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE shared.stored_file (
                id uuid PRIMARY KEY,
                purpose text NOT NULL CHECK (purpose IN ('artisan_document', 'request_photo', 'message_attachment', 'quote_pdf', 'review_photo', 'portfolio_photo')),
                status text NOT NULL CHECK (status IN ('quarantined', 'available', 'rejected', 'shredded')),
                mime text NOT NULL CHECK (mime IN ('image/jpeg', 'image/png', 'image/webp', 'application/pdf')),
                size bigint NOT NULL CHECK (size > 0),
                sha256 char(64) NOT NULL,
                original_name text NULL CHECK (char_length(original_name) <= 120),
                kek_id text NULL,
                wrapped_dek text NULL,
                rejected_reason text NULL,
                created_at timestamptz NOT NULL,
                scanned_at timestamptz NULL,
                shredded_at timestamptz NULL,
                CHECK ((kek_id IS NULL) = (wrapped_dek IS NULL)),
                -- Justificatifs : jamais disponibles sans chiffrement (ADR-0008).
                CHECK (purpose <> 'artisan_document' OR status <> 'available' OR wrapped_dek IS NOT NULL),
                -- Crypto-shredding : plus aucune clé après destruction.
                CHECK (status <> 'shredded' OR wrapped_dek IS NULL)
            )
            SQL);
        $this->addSql("CREATE INDEX stored_file_quarantine_idx ON shared.stored_file (created_at) WHERE status = 'quarantined'");
        $this->addSql('CREATE INDEX stored_file_sha256_idx ON shared.stored_file (sha256)');
        $this->addSql('CREATE INDEX stored_file_kek_idx ON shared.stored_file (kek_id) WHERE kek_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE shared.stored_file');
    }
}
