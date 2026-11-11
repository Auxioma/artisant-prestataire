<?php

declare(strict_types=1);

namespace App\Content\Application\Port;

interface RedirectStore
{
    /**
     * Crée ou remplace la redirection $source → $target, aplatit les chaînes (X → $source devient X → $target)
     * et supprime une éventuelle redirection inverse ($target → …) qui créerait une boucle.
     */
    public function redirect(string $source, string $target, bool $prefix, string $origin): void;

    /**
     * @return array{target: string, status: int}|null
     */
    public function resolve(string $path): ?array;
}
