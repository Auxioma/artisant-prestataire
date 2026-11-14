<?php

declare(strict_types=1);

namespace App\Billing\Application\Subscription;

use App\Billing\Domain\Credit\LedgerEntryType;

/** PR11.2 — une écriture du registre (RG-107) et le solde total juste après elle. */
final readonly class LedgerLine
{
    public function __construct(
        public \DateTimeImmutable $at,
        public LedgerEntryType $type,
        public int $amount,
        public int $balanceAfter,
        public ?string $referenceType,
        public ?string $reference,
        public ?string $reason,
    ) {
    }

    public function label(): string
    {
        return match ($this->type) {
            LedgerEntryType::FreeGrant => 'Attribution gratuite',
            LedgerEntryType::PeriodGrant => 'Recharge de période',
            LedgerEntryType::QuoteDebit => 'Devis envoyé',
            LedgerEntryType::Refund => 'Crédit restitué',
            LedgerEntryType::Manual => 'Geste commercial',
            LedgerEntryType::Expiry => 'Expiration',
            LedgerEntryType::PackPurchase => 'Achat de crédits',
        };
    }
}
