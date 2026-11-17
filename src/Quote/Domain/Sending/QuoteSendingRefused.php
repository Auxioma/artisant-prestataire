<?php

declare(strict_types=1);

namespace App\Quote\Domain\Sending;

/** RG-101 — envoi refusé : la transaction est annulée, aucun crédit débité, le brouillon est conservé. */
final class QuoteSendingRefused extends \DomainException
{
    public function __construct(public readonly RefusalReason $reason, string $message)
    {
        parent::__construct($message);
    }
}
