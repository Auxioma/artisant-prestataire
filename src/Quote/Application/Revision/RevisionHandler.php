<?php

declare(strict_types=1);

namespace App\Quote\Application\Revision;

use App\ArtisanProfile\Application\Quote\QuoteIssuer;
use App\ArtisanProfile\Application\Quote\QuoteIssuerView;
use App\Quote\Application\Editor\QuoteEditorHandler;
use App\Quote\Application\Editor\QuoteForm;
use App\Quote\Application\Pdf\QuotePdfService;
use App\Quote\Domain\Mention\MandatoryMention;
use App\Quote\Domain\Mention\MandatoryMentionsChecker;
use App\Quote\Domain\Mention\MentionCheck;
use App\Quote\Domain\Quote\Quote;
use App\Quote\Domain\Quote\QuoteContent;
use App\Quote\Domain\Quote\QuoteRepository;
use App\Quote\Domain\Quote\QuoteRuleViolation;
use App\QuoteRequest\Application\Pro\ProRequestDetail;
use App\QuoteRequest\Application\Pro\ProRequestView;
use App\QuoteRequest\Domain\Request\QuoteRequestId;
use App\QuoteRequest\Domain\Request\QuoteRequestRepository;
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
 * RG-102 / RG-103 — vie d'un devis envoyé : révision (nouvelle version figée, PDF et E-12b, sans crédit ni place),
 * aperçu de la révision (sans enregistrement) et retrait avant consultation (place libérée, crédit non restitué).
 * Verrous dans l'ordre de l'envoi (ADR-0006) : demande, puis devis.
 */
final readonly class RevisionHandler implements CommandHandler
{
    public function __construct(
        private QuoteRepository $quotes,
        private QuoteRequestRepository $requests,
        private QuotePdfService $pdf,
        private MandatoryMentionsChecker $checker,
        private QueryBus $queries,
        private EventBus $events,
        private SettingsProvider $settings,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    private function revise(ReviseQuote $command): void
    {
        [$issuer, $quote] = $this->sentQuote($command->userId, $command->requestId, true);
        $now = $this->clock->now();
        $content = $this->content($issuer, $command->input);
        $missing = array_values(array_filter($this->check($issuer, $content), static fn (MentionCheck $c): bool => !$c->ok));
        if ([] !== $missing) {
            throw new QuoteRuleViolation('Il manque des informations obligatoires : '.implode(', ', array_map(static fn (MentionCheck $c): string => mb_strtolower($c->label), $missing)).'.');
        }
        $view = $this->queries->ask(new ProRequestDetail($command->userId, $command->requestId));
        if (!$view instanceof ProRequestView) {
            throw new QuoteRuleViolation('Cette demande n’est plus accessible.');
        }
        $version = $quote->nextVersionNumber();
        $pdf = $this->pdf->freeze($this->pdf->document($issuer, $view, $content, $quote->number(), $version, $now, false));
        $quote->revise($content, $now, $pdf);
        $this->quotes->save($quote);
        $this->events->publish(...$quote->releaseEvents());
        $this->record($command->userId, 'quote.revised', $quote, ['version' => $version, 'ttc_cents' => $content->totals->ttc->amount, 'pdf_sha256' => $pdf->sha256], $now);
    }

    private function withdraw(WithdrawQuote $command): void
    {
        $issuer = $this->issuer($command->userId);
        $request = $this->requests->findForUpdate(new QuoteRequestId($command->requestId)) ?? throw new QuoteRuleViolation('Demande introuvable.');
        [, $quote] = $this->sentQuote($command->userId, $command->requestId, true, $issuer);
        $now = $this->clock->now();
        $quote->withdraw($now);
        $request->releaseQuote($now);
        $this->quotes->save($quote);
        $this->events->publish(...$quote->releaseEvents(), ...$request->releaseEvents());
        $this->record($command->userId, 'quote.withdrawn', $quote, ['request' => $request->number(), 'credit_refunded' => false], $now);
    }

    /** Utilisé par `PreviewRevisionHandler` (bus de requêtes). */
    public function preview(PreviewRevision $query): RevisionPreview
    {
        [$issuer] = $this->sentQuote($query->userId, $query->requestId, false);
        $content = $this->content($issuer, $query->input);

        return new RevisionPreview($content, $this->check($issuer, $content));
    }

    public function __invoke(ReviseQuote|WithdrawQuote $command): void
    {
        if ($command instanceof ReviseQuote) {
            $this->revise($command);

            return;
        }
        $this->withdraw($command);
    }

    private function issuer(string $userId): QuoteIssuerView
    {
        $issuer = $this->queries->ask(new QuoteIssuer($userId));

        return $issuer instanceof QuoteIssuerView ? $issuer : throw new QuoteRuleViolation('Devis introuvable.');
    }

    /** @return array{QuoteIssuerView, Quote} */
    private function sentQuote(string $userId, string $requestId, bool $lock, ?QuoteIssuerView $issuer = null): array
    {
        $issuer ??= $this->issuer($userId);
        $quote = $this->quotes->findByRequestAndArtisan($requestId, $issuer->artisanId);
        if (null !== $quote && $lock) {
            $quote = $this->quotes->findForUpdate($quote->id());
        }
        if (null === $quote || !$quote->status()->isRevisable()) {
            throw new QuoteRuleViolation('Ce devis ne peut plus être modifié.');
        }

        return [$issuer, $quote];
    }

    /** @param array<string, mixed> $input */
    private function content(QuoteIssuerView $issuer, array $input): QuoteContent
    {
        return QuoteForm::content($input, $issuer->vatExempt, $this->settings->int(SettingKey::QuotesDefaultValidityDays));
    }

    /** @return list<MentionCheck> */
    private function check(QuoteIssuerView $issuer, QuoteContent $content): array
    {
        $mentions = array_values(array_filter(array_map(MandatoryMention::tryFrom(...), $this->settings->stringList(SettingKey::QuotesMandatoryMentions))));

        return $this->checker->check($content, QuoteEditorHandler::facts($issuer, $this->clock->now()), $mentions);
    }

    /** @param array<string, mixed> $context */
    private function record(string $userId, string $action, Quote $quote, array $context, \DateTimeImmutable $now): void
    {
        $this->audit->record(new AuditRecord(
            id: $this->ids->next(),
            occurredAt: $now,
            actorType: 'user',
            actorId: $userId,
            action: $action,
            subjectType: 'quote',
            subjectId: $quote->id()->value,
            context: ['number' => $quote->number()] + $context,
        ));
    }
}
