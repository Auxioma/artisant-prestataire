<?php

declare(strict_types=1);

namespace App\Billing\Application\Reconciliation;

final readonly class ReconciliationRun
{
    public function __construct(public \DateTimeImmutable $finishedAt, public bool $stripeChecked, public int $checked, public int $issues)
    {
    }
}
