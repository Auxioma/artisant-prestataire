<?php

declare(strict_types=1);

namespace App\Billing\Domain\Credit;

/** Compartiments de crédit (ADR-0006) : gratuit (RG-044), forfait (période), supplément (packs). */
enum CreditBucket: string
{
    case Free = 'free';
    case Plan = 'plan';
    case Extra = 'extra';
}
