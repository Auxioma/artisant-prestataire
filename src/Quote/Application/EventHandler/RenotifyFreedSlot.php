<?php

declare(strict_types=1);

namespace App\Quote\Application\EventHandler;

use App\Quote\Domain\Quote\Event\QuoteStatusChanged;
use App\Quote\Domain\Quote\QuoteRepository;
use App\Quote\Domain\Quote\QuoteStatus;
use App\QuoteRequest\Application\Dispatch\RenotifyRequest;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\EventHandler;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;

/**
 * P-09 — un devis retiré (ou expiré sans décision) libère une place : les artisans déjà destinataires de la demande la retrouvent dans leur
 * prochain digest (`requests.renotify_on_slot_freed`), sauf l'artisan qui s'est retiré (P-08) et ceux qui ont déjà un
 * devis actif. Seul le module Quote sait qui a répondu : il fournit la liste à exclure.
 */
final readonly class RenotifyFreedSlot implements EventHandler
{
    public function __construct(private QuoteRepository $quotes, private CommandBus $commands, private SettingsProvider $settings)
    {
    }

    public function __invoke(QuoteStatusChanged $event): void
    {
        if (!\in_array($event->to, [QuoteStatus::Withdrawn->value, QuoteStatus::Expired->value], true) || !$this->settings->bool(SettingKey::RequestsRenotifyOnSlotFreed)) {
            return;
        }
        $exclude = array_values(array_unique([$event->artisanId, ...$this->quotes->artisansWithActiveQuote($event->requestId)]));
        $this->commands->dispatch(new RenotifyRequest($event->requestId, $exclude));
    }
}
