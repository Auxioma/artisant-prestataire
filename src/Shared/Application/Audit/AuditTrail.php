<?php

declare(strict_types=1);

namespace App\Shared\Application\Audit;

use App\Shared\Domain\Audit\AuditRecord;

interface AuditTrail
{
    public function record(AuditRecord $record): void;

    /**
     * Recalcule la chaîne de hash ; renvoie l'identifiant de la première entrée incohérente, ou null si intègre.
     */
    public function verifyChain(): ?string;
}
