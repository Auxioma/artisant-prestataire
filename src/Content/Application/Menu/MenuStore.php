<?php

declare(strict_types=1);

namespace App\Content\Application\Menu;

/** Menus (`content.menu_item`). */
interface MenuStore
{
    /** @return list<MenuItem> tous les éléments, par menu puis position */
    public function all(): array;

    public function find(string $id): ?MenuItem;

    public function save(MenuItem $item, \DateTimeImmutable $at): void;

    public function move(string $id, int $direction): void;

    public function delete(string $id): void;
}
