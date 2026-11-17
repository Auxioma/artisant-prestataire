<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Persistence;

use App\Notification\Application\Port\TemplateStore;
use App\Notification\Application\Template;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalTemplateStore implements TemplateStore
{
    public function __construct(private Connection $connection)
    {
    }

    public function find(string $code): ?Template
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM notification.template WHERE code = :c', ['c' => $code]);
        if (false === $row) {
            return null;
        }
        $text = static fn (mixed $v): ?string => null === $v ? null : DbalValue::string($v);

        return new Template(
            DbalValue::string($row['code']),
            self::pgArray(DbalValue::string($row['channels'])),
            true === $row['active'],
            DbalValue::int($row['version']),
            $text($row['subject']),
            $text($row['preheader']),
            $text($row['body_html']),
            $text($row['body_text']),
            $text($row['in_app_body']),
        );
    }

    /** @return list<string> */
    public static function pgArray(string $value): array
    {
        $value = trim($value, '{}');

        return '' === $value ? [] : array_map(static fn (string $v): string => trim($v, '"'), explode(',', $value));
    }
}
