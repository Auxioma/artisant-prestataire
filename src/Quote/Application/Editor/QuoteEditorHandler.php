<?php

declare(strict_types=1);

namespace App\Quote\Application\Editor;

use App\ArtisanProfile\Application\Quote\QuoteIssuer;
use App\ArtisanProfile\Application\Quote\QuoteIssuerView;
use App\Billing\Application\Credit\CreditGaugeOf;
use App\Billing\Domain\Credit\CreditGauge;
use App\Quote\Domain\Mention\IssuerFacts;
use App\Quote\Domain\Mention\MandatoryMention;
use App\Quote\Domain\Mention\MandatoryMentionsChecker;
use App\Quote\Domain\Quote\QuoteRepository;
use App\Quote\Domain\Quote\QuoteStatus;
use App\QuoteRequest\Application\Pro\ProRequestAccess;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Time\Clock;

final readonly class QuoteEditorHandler implements QueryHandler
{
    public function __construct(
        private ProRequestAccess $access,
        private QuoteRepository $quotes,
        private QueryBus $queries,
        private SettingsProvider $settings,
        private MandatoryMentionsChecker $checker,
        private Clock $clock,
    ) {
    }

    public function __invoke(QuoteEditor $query): ?QuoteEditorView
    {
        $issuer = $this->queries->ask(new QuoteIssuer($query->userId));
        $card = $this->access->card($query->userId, $query->requestId);
        if (!$issuer instanceof QuoteIssuerView || null === $card) {
            return null;
        }
        $quote = $this->quotes->findByRequestAndArtisan($query->requestId, $issuer->artisanId);
        $content = $quote?->current()->content;
        $gauge = $this->queries->ask(new CreditGaugeOf($query->userId));

        return new QuoteEditorView(
            $card,
            $issuer,
            $quote?->id()->value,
            $quote?->status() ?? QuoteStatus::Draft,
            $quote?->number(),
            $content,
            null === $content ? [] : $this->checker->check($content, self::facts($issuer, $this->clock->now()), $this->mentions()),
            $gauge instanceof CreditGauge ? $gauge->remaining : null,
            $this->settings->int(SettingKey::QuotesDefaultValidityDays),
            $quote?->updatedAt(),
            $quote?->sentAt(),
            $quote?->current()->number ?? 1,
        );
    }

    public static function facts(QuoteIssuerView $issuer, \DateTimeImmutable $at): IssuerFacts
    {
        return new IssuerFacts($issuer->legalName, $issuer->siret, $issuer->address, $issuer->insuranceRequired, $issuer->decennialRequired, $issuer->insuredOn('rc_pro', $at), $issuer->insuredOn('decennial', $at));
    }

    /** @return list<MandatoryMention> */
    private function mentions(): array
    {
        return array_values(array_filter(array_map(MandatoryMention::tryFrom(...), $this->settings->stringList(SettingKey::QuotesMandatoryMentions))));
    }
}
