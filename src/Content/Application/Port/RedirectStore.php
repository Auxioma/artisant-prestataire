<?php

declare(strict_types=1);

namespace App\Content\Application\Port;

interface RedirectStore
{
    /**
     * Crée ou remplace la redirection $source → $target, aplatit les chaînes (X → $source devient X → $target)
     * et supprime une éventuelle redirection inverse ($target → …) qui créerait une boucle.
     */
    public function redirect(string $source, string $target, bool $prefix, string $origin, int $status = 301): void;

    /**
     * BO12.2 — redirections enregistrées, filtrées sur la source ou la cible.
     *
     * @return array{total: int, items: list<array{id: int, source: string, target: string, status: int, prefix: bool, origin: string, hits: int, updated_at: string}>}
     */
    public function list(string $search, int $offset, int $limit): array;

    public function delete(int $id): void;

    /**
     * @return array{target: string, status: int}|null
     */
    public function resolve(string $path): ?array;
}
