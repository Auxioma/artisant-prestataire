<?php

declare(strict_types=1);

namespace App\QuoteRequest\Domain\Request;

/** P11.6 — préférence de contact : messagerie uniquement, ou téléphone autorisé après acceptation d'un devis (RG-022bis). */
enum ContactPreference: string
{
    case MessagingOnly = 'messaging_only';
    case PhoneAfterAcceptance = 'phone_after_acceptance';

    public function label(): string
    {
        return match ($this) {
            self::MessagingOnly => 'Messagerie TrouveMoi uniquement',
            self::PhoneAfterAcceptance => 'Téléphone autorisé après acceptation d’un devis',
        };
    }
}
