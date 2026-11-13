<?php

declare(strict_types=1);

namespace App\Quote\Application\Editor;

use App\ArtisanProfile\Application\Quote\QuoteIssuer;
use App\ArtisanProfile\Application\Quote\QuoteIssuerView;
use App\Quote\Domain\Quote\Quote;
use App\Quote\Domain\Quote\QuoteId;
use App\Quote\Domain\Quote\QuoteRepository;
use App\Quote\Domain\Quote\QuoteRuleViolation;
use App\Quote\Domain\Quote\QuoteStatus;
use App\QuoteRequest\Application\Pro\ProRequestAccess;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Time\Clock;

/**
 * PR04 — crée ou met à jour le brouillon de l'artisan sur la demande (un seul devis par couple, RG-101 d). Réservé à
 * l'artisan vérifié pouvant répondre (demande reçue, ouverte, places restantes — QUOTE_REQUEST_ANSWER).
 */
final readonly class SaveQuoteDraftHandler implements CommandHandler
{
    public function __construct(
        private QuoteRepository $quotes,
        private ProRequestAccess $access,
        private QueryBus $queries,
        private SettingsProvider $settings,
        private Clock $clock,
    ) {
    }

    public function __invoke(SaveQuoteDraft $command): void
    {
        $issuer = $this->queries->ask(new QuoteIssuer($command->userId));
        if (!$issuer instanceof QuoteIssuerView || !$issuer->verified || !$this->access->canAnswer($command->userId, $command->requestId)) {
            throw new QuoteRuleViolation('Vous ne pouvez pas répondre à cette demande.');
        }
        $now = $this->clock->now();
        $content = QuoteForm::content($command->input, $issuer->vatExempt, $this->settings->int(SettingKey::QuotesDefaultValidityDays));
        $quote = $this->quotes->findByRequestAndArtisan($command->requestId, $issuer->artisanId);
        if (null === $quote) {
            $quote = Quote::draft(new QuoteId($command->quoteId), $command->requestId, $issuer->artisanId, $content, $now);
            $this->access->recordConsultation($issuer->artisanId, $command->requestId, $now);
        } elseif (QuoteStatus::Draft === $quote->status()) {
            $quote->updateDraft($content, $now);
        } else {
            throw new QuoteRuleViolation('Ce devis est déjà envoyé : vous pourrez le réviser depuis « Mes devis ».');
        }
        $this->quotes->save($quote);
    }
}
