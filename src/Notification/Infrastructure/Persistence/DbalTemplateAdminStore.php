<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Persistence;

use App\Notification\Application\Admin\TemplateAdminStore;
use App\Notification\Application\Admin\TemplateDetail;
use App\Notification\Application\Admin\TemplateVersion;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalTemplateAdminStore implements TemplateAdminStore
{
    public function __construct(private Connection $connection)
    {
    }

    public function all(): array
    {
        return array_map(self::view(...), $this->connection->fetchAllAssociative('SELECT * FROM notification.template ORDER BY code'));
    }

    public function find(string $code): ?TemplateDetail
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM notification.template WHERE code = :c', ['c' => $code]);

        return false === $row ? null : self::view($row);
    }

    public function versions(string $code): array
    {
        return array_map(static fn (array $r): TemplateVersion => new TemplateVersion(DbalValue::int($r['version']), DbalValue::nullableString($r['subject']), new \DateTimeImmutable(DbalValue::string($r['created_at'])), DbalValue::nullableString($r['created_by'])), $this->connection->fetchAllAssociative(
            'SELECT version, subject, created_at, created_by FROM notification.template_version WHERE code = :c ORDER BY version DESC',
            ['c' => $code],
        ));
    }

    public function versionContent(string $code, int $version): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT subject, preheader, body_html, body_text, in_app_body FROM notification.template_version WHERE code = :c AND version = :v', ['c' => $code, 'v' => $version]);

        return false === $row ? null : [
            'subject' => DbalValue::nullableString($row['subject']),
            'preheader' => DbalValue::nullableString($row['preheader']),
            'body_html' => DbalValue::nullableString($row['body_html']),
            'body_text' => DbalValue::nullableString($row['body_text']),
            'in_app_body' => DbalValue::nullableString($row['in_app_body']),
        ];
    }

    public function save(string $code, array $content, bool $active, ?string $adminId, \DateTimeImmutable $at): int
    {
        return $this->connection->transactional(static function (Connection $c) use ($code, $content, $active, $adminId, $at): int {
            $version = DbalValue::int($c->fetchOne('UPDATE notification.template SET subject = :s, preheader = :p, body_html = :h, body_text = :t, in_app_body = :i, active = :a, version = version + 1, customized = true, updated_at = :at, updated_by = :by WHERE code = :c RETURNING version', [
                's' => $content['subject'], 'p' => $content['preheader'], 'h' => $content['body_html'], 't' => $content['body_text'], 'i' => $content['in_app_body'],
                'a' => $active ? 'true' : 'false', 'at' => $at->format(\DATE_ATOM), 'by' => $adminId, 'c' => $code,
            ]));
            $c->insert('notification.template_version', ['code' => $code, 'version' => $version, 'subject' => $content['subject'], 'preheader' => $content['preheader'], 'body_html' => $content['body_html'], 'body_text' => $content['body_text'], 'in_app_body' => $content['in_app_body'], 'created_at' => $at->format(\DATE_ATOM), 'created_by' => $adminId]);

            return $version;
        });
    }

    /** @param array<string, mixed> $r */
    private static function view(array $r): TemplateDetail
    {
        return new TemplateDetail(
            DbalValue::string($r['code']),
            DbalValue::string($r['label']),
            DbalValue::string($r['recipient']),
            DbalTemplateStore::pgArray(DbalValue::string($r['channels'])),
            DbalTemplateStore::pgArray(DbalValue::string($r['variables'])),
            DbalValue::nullableString($r['subject']),
            DbalValue::nullableString($r['preheader']),
            DbalValue::nullableString($r['body_html']),
            DbalValue::nullableString($r['body_text']),
            DbalValue::nullableString($r['in_app_body']),
            true === $r['active'],
            DbalValue::int($r['version']),
            true === $r['customized'],
            new \DateTimeImmutable(DbalValue::string($r['updated_at'])),
        );
    }
}
