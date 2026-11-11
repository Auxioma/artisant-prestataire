<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\Administration\Application\Port\AuditTrail;
use App\Administration\Domain\Audit\AuditRecord;

final class InMemoryAuditTrail implements AuditTrail
{
    /** @var list<AuditRecord> */
    public array $records = [];

    public function record(AuditRecord $record): void
    {
        $this->records[] = $record;
    }

    public function verifyChain(): ?string
    {
        return null;
    }
}
