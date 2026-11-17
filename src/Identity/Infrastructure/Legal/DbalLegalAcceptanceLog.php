<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Legal;

use App\Identity\Application\Legal\LegalDocumentVersion;
use App\Identity\Application\Port\LegalAcceptanceLog;
use Doctrine\DBAL\Connection;

final readonly class DbalLegalAcceptanceLog implements LegalAcceptanceLog
{
    public function __construct(private Connection $connection)
    {
    }

    public function record(string $id, string $userId, LegalDocumentVersion $document, \DateTimeImmutable $at, ?string $ipHash): void
    {
        // Le compte est inséré par l'ORM au flush : clé étrangère DEFERRABLE, vérifiée à la validation de la transaction.
        $this->connection->executeStatement(
            'INSERT INTO identity.legal_acceptance (id, user_id, legal_document_id, content_hash, accepted_at, ip_hash)
             VALUES (:id, :user, :doc, :hash, :at, :ip) ON CONFLICT (user_id, legal_document_id) DO NOTHING',
            ['id' => $id, 'user' => $userId, 'doc' => $document->id, 'hash' => $document->contentHash, 'at' => $at->format(\DATE_ATOM), 'ip' => $ipHash],
        );
    }

    public function hasAcceptedSince(string $userId, LegalDocumentVersion $document): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM identity.legal_acceptance a JOIN identity.legal_document d ON d.id = a.legal_document_id
             WHERE a.user_id = :user AND d.type = :type AND d.version >= :version LIMIT 1',
            ['user' => $userId, 'type' => $document->type->value, 'version' => $document->version],
        );
    }

    public function hasAccepted(string $userId, LegalDocumentVersion $document): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM identity.legal_acceptance WHERE user_id = :user AND legal_document_id = :doc',
            ['user' => $userId, 'doc' => $document->id],
        );
    }
}
