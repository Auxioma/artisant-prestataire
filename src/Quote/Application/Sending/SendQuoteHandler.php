<?php

declare(strict_types=1);

namespace App\Quote\Application\Sending;

use App\ArtisanProfile\Application\Quote\QuoteIssuer;
use App\ArtisanProfile\Application\Quote\QuoteIssuerView;
use App\Billing\Application\Credit\InsufficientCredits;
use App\Billing\Application\Credit\QuoteCreditAccount;
use App\Billing\Domain\Credit\CreditBucket;
use App\Quote\Application\Editor\QuoteEditorHandler;
use App\Quote\Application\Pdf\QuotePdfService;
use App\Quote\Application\Port\QuoteNumberAllocator;
use App\Quote\Domain\Mention\MandatoryMention;
use App\Quote\Domain\Mention\MandatoryMentionsChecker;
use App\Quote\Domain\Quote\QuoteRepository;
use App\Quote\Domain\Sending\QuoteSendingPolicy;
use App\Quote\Domain\Sending\QuoteSendingRefused;
use App\Quote\Domain\Sending\RefusalReason;
use App\Quote\Domain\Sending\SendingFacts;
use App\QuoteRequest\Application\Pro\ProRequestDetail;
use App\QuoteRequest\Application\Pro\ProRequestView;
use App\QuoteRequest\Domain\Request\QuoteRequestId;
use App\QuoteRequest\Domain\Request\QuoteRequestRepository;
use App\QuoteRequest\Domain\Request\RequestStatus;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/**
 * RG-101 / ADR-0006 — envoi atomique, une transaction (bus de commandes) : verrous de la demande, du devis (relu) puis du
 * compte de crédits (ordre fixe), politique (a)–(f), numéro, PDF figé (PR04.4), envoi de la v1, débit d'un crédit (RG-104),
 * place occupée sur la demande (« complète » à la dernière, RG-070). Tout refus annule la transaction : aucun débit,
 * brouillon conservé.
 */
final readonly class SendQuoteHandler implements CommandHandler
{
    public const string NUMBER_PREFIX = 'DEV';
    private const string NUMBER_FORMAT = '%s-%d-%04d';

    public function __construct(
        private QuoteRepository $quotes,
        private QuoteRequestRepository $requests,
        private QuoteCreditAccount $credits,
        private QuoteNumberAllocator $numbers,
        private QuotePdfService $pdf,
        private MandatoryMentionsChecker $checker,
        private QuoteSendingPolicy $policy,
        private QueryBus $queries,
        private EventBus $events,
        private SettingsProvider $settings,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(SendQuote $command): void
    {
        $issuer = $this->queries->ask(new QuoteIssuer($command->userId));
        $quote = $issuer instanceof QuoteIssuerView ? $this->quotes->findByRequestAndArtisan($command->requestId, $issuer->artisanId) : null;
        if (!$issuer instanceof QuoteIssuerView || null === $quote) {
            throw new QuoteSendingRefused(RefusalReason::AlreadySent, 'Enregistrez d’abord votre devis.');
        }
        $now = $this->clock->now();

        // 1. Verrou de la demande, 2. du devis (relu : un envoi concurrent a pu l'envoyer entre-temps), 3. du compte de
        // crédits — ordre fixe pour tous les envois : pas d'interblocage (ADR-0006).
        $request = $this->requests->findForUpdate(new QuoteRequestId($command->requestId)) ?? throw new QuoteSendingRefused(RefusalReason::RequestClosed, 'Cette demande n’accepte plus de devis.');
        $quote = $this->quotes->findForUpdate($quote->id()) ?? throw new QuoteSendingRefused(RefusalReason::AlreadySent, 'Enregistrez d’abord votre devis.');
        $balance = $this->credits->lockForQuote($issuer->artisanId);
        $content = $quote->current()->content;

        // 3. Politique (a)–(f).
        $this->policy->assertCanSend(new SendingFacts(
            RequestStatus::Open === $request->status() && (null === $request->expiresAt() || $now < $request->expiresAt()),
            $request->activeQuotesCount(),
            $request->maxQuotes(),
            $issuer->verified,
            $quote->status(),
            $this->checker->check($content, QuoteEditorHandler::facts($issuer, $now), $this->mentions()),
            $balance?->total() ?? 0,
        ));

        // 4. Numéro, PDF figé, envoi de la v1.
        $year = (int) $now->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y');
        $number = \sprintf(self::NUMBER_FORMAT, self::NUMBER_PREFIX, $year, $this->numbers->next($issuer->artisanId, $year));
        $view = $this->queries->ask(new ProRequestDetail($command->userId, $command->requestId));
        if (!$view instanceof ProRequestView) {
            throw new QuoteSendingRefused(RefusalReason::RequestClosed, 'Cette demande n’accepte plus de devis.');
        }
        $pdf = $this->pdf->freeze($this->pdf->document($issuer, $view, $content, $number, 1, $now, false));
        $quote->send($number, $now, $pdf);
        $this->quotes->save($quote);

        // 5. Débit (RG-100, RG-104), 6. place occupée (RG-070 : « complète » à la dernière).
        try {
            $bucket = $this->credits->debitForQuote($this->ids->next(), $issuer->artisanId, $quote->id()->value, $this->order(), $now);
        } catch (InsufficientCredits) {
            throw new QuoteSendingRefused(RefusalReason::NoCredit, 'Vous n’avez plus de crédit : souscrivez un forfait pour envoyer ce devis.');
        }
        $request->recordQuote($now);

        $this->events->publish(...$quote->releaseEvents(), ...$request->releaseEvents());
        $this->audit->record(new AuditRecord(
            id: $this->ids->next(),
            occurredAt: $now,
            actorType: 'user',
            actorId: $command->userId,
            action: 'quote.sent',
            subjectType: 'quote',
            subjectId: $quote->id()->value,
            context: ['number' => $number, 'request' => $request->number(), 'ttc_cents' => $content->totals->ttc->amount, 'bucket' => $bucket->value, 'pdf_sha256' => $pdf->sha256],
        ));
    }

    /** @return list<MandatoryMention> */
    private function mentions(): array
    {
        return array_values(array_filter(array_map(MandatoryMention::tryFrom(...), $this->settings->stringList(SettingKey::QuotesMandatoryMentions))));
    }

    /** @return list<CreditBucket> RG-104 — ordre de consommation (`credits.consumption_order`). */
    private function order(): array
    {
        return array_values(array_filter(array_map(CreditBucket::tryFrom(...), $this->settings->stringList(SettingKey::CreditsConsumptionOrder))));
    }
}
