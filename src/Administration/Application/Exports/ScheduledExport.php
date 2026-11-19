<?php

declare(strict_types=1);

namespace App\Administration\Application\Exports;

/** Un fichier produit par un export planifié (BO15.2). */
final readonly class ScheduledExport
{
    public function __construct(
        public string $id,
        public string $kind,
        public string $periodLabel,
        public string $fileId,
        public int $rows,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
