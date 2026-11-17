<?php

declare(strict_types=1);

namespace App\Billing\Application\Invoice;

use App\Shared\Application\Bus\Command;

/** BO (rôle Finance, ré-authentification) — remboursement total ou partiel d'une facture payée, sous forme d'avoir. */
final readonly class RefundInvoice implements Command
{
    public const array REASONS = [
        'duplicate' => 'Paiement en double',
        'order_change' => 'Changement de formule',
        'product_unsatisfactory' => 'Geste commercial (insatisfaction)',
        'fraudulent' => 'Paiement frauduleux',
    ];

    /** @param ?int $amountCents null : remboursement du solde restant */
    public function __construct(public string $adminId, public string $invoiceId, public ?int $amountCents, public string $reason, public string $comment)
    {
    }
}
