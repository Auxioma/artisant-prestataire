<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Menu;

use App\Content\Application\Menu\MenuItem;
use App\Content\Application\Menu\MenuStore;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalMenuStore implements MenuStore
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(private Connection $connection)
    {
    }

    public function all(): array
    {
        return array_map(self::view(...), $this->connection->fetchAllAssociative('SELECT * FROM content.menu_item ORDER BY menu, position, label'));
    }

    public function find(string $id): ?MenuItem
    {
        if (1 !== preg_match(self::UUID, $id)) {
            return null;
        }
        $row = $this->connection->fetchAssociative('SELECT * FROM content.menu_item WHERE id = :id', ['id' => $id]);

        return false === $row ? null : self::view($row);
    }

    public function save(MenuItem $item, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(
            'INSERT INTO content.menu_item (id, menu, label, link, icon, new_tab, audience, position, active, updated_at)
             VALUES (:id, :menu, :label, :link, :icon, :tab, :audience, (SELECT coalesce(max(position) + 1, 0) FROM content.menu_item WHERE menu = :menu), :active, :at)
             ON CONFLICT (id) DO UPDATE SET label = EXCLUDED.label, link = EXCLUDED.link, new_tab = EXCLUDED.new_tab, audience = EXCLUDED.audience, active = EXCLUDED.active, updated_at = EXCLUDED.updated_at',
            ['id' => $item->id, 'menu' => $item->menu, 'label' => $item->label, 'link' => $item->link, 'icon' => $item->icon, 'tab' => $item->newTab ? 'true' : 'false', 'audience' => $item->audience, 'active' => $item->active ? 'true' : 'false', 'at' => $at->format(\DATE_ATOM)],
        );
    }

    public function move(string $id, int $direction): void
    {
        $menu = $this->connection->fetchOne('SELECT menu FROM content.menu_item WHERE id = :id', ['id' => $id]);
        if (!\is_string($menu)) {
            return;
        }
        $ids = array_map(DbalValue::string(...), $this->connection->fetchFirstColumn('SELECT id FROM content.menu_item WHERE menu = :m ORDER BY position, label', ['m' => $menu]));
        $index = array_search($id, $ids, true);
        if (false === $index || !isset($ids[$index + $direction])) {
            return;
        }
        [$ids[$index], $ids[$index + $direction]] = [$ids[$index + $direction], $ids[$index]];
        foreach ($ids as $position => $rowId) {
            $this->connection->update('content.menu_item', ['position' => $position], ['id' => $rowId]);
        }
    }

    public function delete(string $id): void
    {
        $this->connection->delete('content.menu_item', ['id' => $id]);
    }

    /** @param array<string, mixed> $r */
    private static function view(array $r): MenuItem
    {
        return new MenuItem(DbalValue::string($r['id']), DbalValue::string($r['menu']), DbalValue::string($r['label']), DbalValue::string($r['link']), DbalValue::nullableString($r['icon']), true === $r['new_tab'], DbalValue::string($r['audience']), DbalValue::int($r['position']), true === $r['active']);
    }
}
