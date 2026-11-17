<?php

declare(strict_types=1);

namespace App\Billing\Domain\Credit;

/** Écritures du registre de crédits (ADR-0006) : jamais modifiées ni supprimées (RG-107). */
enum LedgerEntryType: string
{
    case FreeGrant = 'free_grant';
    case PeriodGrant = 'period_grant';
    case QuoteDebit = 'quote_debit';
    case Refund = 'refund';
    case Manual = 'manual';
    case Expiry = 'expiry';
    case PackPurchase = 'pack_purchase';
}
