<?php

declare(strict_types=1);

namespace App\Billing\Application\Reconciliation;

use App\Shared\Application\Bus\Command;

final readonly class ResolveReconciliationIssue implements Command
{
    public function __construct(public string $adminId, public string $issueId)
    {
    }
}
