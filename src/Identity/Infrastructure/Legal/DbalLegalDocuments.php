<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Legal;

use App\Identity\Application\Legal\LegalDocumentVersion;
use App\Identity\Application\Port\LegalDocuments;
use App\Identity\Domain\Legal\LegalDocumentType;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

final readonly class DbalLegalDocuments implements LegalDocuments
{
    public function __construct(
        private Connection $connection,
        #[Target('app.legal_sanitizer')]
        private HtmlSanitizerInterface $sanitizer,
    ) {
    }

    public function current(LegalDocumentType $type, \DateTimeImmutable $now): ?LegalDocumentVersion
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM identity.legal_document WHERE type = :type AND published_at <= :now ORDER BY version DESC LIMIT 1',
            ['type' => $type->value, 'now' => $now->format(\DATE_ATOM)],
        );

        return false === $row ? null : self::version($row);
    }

    public function get(string $id): ?LegalDocumentVersion
    {
        if (1 !== preg_match('/^[0-9a-f-]{36}$/', $id)) {
            return null;
        }
        $row = $this->connection->fetchAssociative('SELECT * FROM identity.legal_document WHERE id = :id', ['id' => $id]);

        return false === $row ? null : self::version($row);
    }

    public function publish(string $id, LegalDocumentType $type, string $title, string $html, bool $substantial, \DateTimeImmutable $publishedAt, ?string $publishedBy): LegalDocumentVersion
    {
        $clean = $this->sanitizer->sanitize($html);
        $version = DbalValue::int($this->connection->fetchOne('SELECT COALESCE(MAX(version), 0) + 1 FROM identity.legal_document WHERE type = :t', ['t' => $type->value]));
        $this->connection->insert('identity.legal_document', [
            'id' => $id,
            'type' => $type->value,
            'version' => $version,
            'title' => $title,
            'content_html' => $clean,
            'content_hash' => hash('sha256', $clean),
            'substantial' => $substantial ? 'true' : 'false',
            'published_at' => $publishedAt->format(\DATE_ATOM),
            'published_by' => $publishedBy,
        ]);

        return new LegalDocumentVersion($id, $type, $version, $title, $clean, hash('sha256', $clean), $substantial, $publishedAt);
    }

    /** @param array<string, mixed> $row */
    private static function version(array $row): LegalDocumentVersion
    {
        return new LegalDocumentVersion(
            DbalValue::string($row['id']),
            LegalDocumentType::from(DbalValue::string($row['type'])),
            DbalValue::int($row['version']),
            DbalValue::string($row['title']),
            DbalValue::string($row['content_html']),
            DbalValue::string($row['content_hash']),
            true === $row['substantial'],
            new \DateTimeImmutable(DbalValue::string($row['published_at'])),
        );
    }
}
