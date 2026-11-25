<?php

declare(strict_types=1);

namespace App\Shared\Domain\Time;

/**
 * Horloge injectée : le domaine n'appelle jamais `new \DateTimeImmutable()` directement (testabilité des délais RG).
 */
interface Clock
{
    public function now(): \DateTimeImmutable;
}
