<?php

declare(strict_types=1);

namespace App\Billing\Application\Payment;

use App\Shared\Application\Bus\Command;

/** Aligne le produit et les prix Stripe d'un forfait sur BO08 (après publication, modification, archivage). */
final readonly class SyncPlanWithStripe implements Command
{
    public function __construct(public string $planId)
    {
    }
}
