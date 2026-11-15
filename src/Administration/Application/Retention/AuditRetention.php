<?php

declare(strict_types=1);

namespace App\Administration\Application\Retention;

/** Purge de tête du journal d'audit en conservant un point d'ancrage de la chaîne d'empreintes. */
interface AuditRetention
{
    /** @return int nombre d'entrées purgées */
    public function purgeBefore(\DateTimeImmutable $before, \DateTimeImmutable $now): int;
}
