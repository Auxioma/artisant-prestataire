<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Label;

use App\Content\Application\Label\LabelStore;
use App\Content\Application\Label\UiLabel;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalLabelStore implements LabelStore
{
    public function __construct(private Connection $connection)
    {
    }

    public function all(string $locale): array
    {
        return array_map(self::view(...), $this->connection->fetchAllAssociative('SELECT * FROM content.ui_label WHERE locale = :l ORDER BY key', ['l' => $locale]));
    }

    public function overrides(string $locale): array
    {
        $map = [];
        foreach ($this->connection->fetchAllAssociative('SELECT key, text FROM content.ui_label WHERE locale = :l AND text IS NOT NULL', ['l' => $locale]) as $row) {
            $map[DbalValue::string($row['key'])] = DbalValue::string($row['text']);
        }

        return $map;
    }

    public function find(string $key, string $locale): ?UiLabel
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM content.ui_label WHERE key = :k AND locale = :l', ['k' => $key, 'l' => $locale]);

        return false === $row ? null : self::view($row);
    }

    public function setText(string $key, string $locale, ?string $text, ?string $adminId, \DateTimeImmutable $at): void
    {
        $this->connection->update('content.ui_label', ['text' => $text, 'updated_at' => $at->format(\DATE_ATOM), 'updated_by' => $adminId], ['key' => $key, 'locale' => $locale]);
    }

    public function sync(array $defaults, string $locale, \DateTimeImmutable $at): int
    {
        $existing = array_flip(array_map(DbalValue::string(...), $this->connection->fetchFirstColumn('SELECT key FROM content.ui_label WHERE locale = :l', ['l' => $locale])));
        $added = 0;
        foreach ($defaults as $key => $default) {
            $this->connection->executeStatement(
                'INSERT INTO content.ui_label (key, locale, default_text, updated_at) VALUES (:k, :l, :d, :at)
                 ON CONFLICT (key, locale) DO UPDATE SET default_text = EXCLUDED.default_text WHERE content.ui_label.default_text <> EXCLUDED.default_text',
                ['k' => $key, 'l' => $locale, 'd' => $default, 'at' => $at->format(\DATE_ATOM)],
            );
            $added += isset($existing[$key]) ? 0 : 1;
        }

        return $added;
    }

    /** @param array<string, mixed> $r */
    private static function view(array $r): UiLabel
    {
        return new UiLabel(DbalValue::string($r['key']), DbalValue::string($r['locale']), DbalValue::string($r['default_text']), DbalValue::nullableString($r['text']), new \DateTimeImmutable(DbalValue::string($r['updated_at'])));
    }
}
