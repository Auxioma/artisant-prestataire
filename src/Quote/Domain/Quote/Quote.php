<?php

declare(strict_types=1);

namespace App\Quote\Domain\Quote;

use App\Quote\Domain\Quote\Event\QuoteRevised;
use App\Quote\Domain\Quote\Event\QuoteStatusChanged;
use App\Shared\Domain\AggregateRoot;

/**
 * Devis d'un artisan sur une demande (un seul par couple demande × artisan, RG-101 d). Brouillon modifiable (ne consomme
 * rien), envoi de la v1 (numéro attribué, validité démarrée), révisions RG-102 (v2, v3… tant que non décidé), puis
 * décisions RG-121. Le contrôle atomique de l'envoi (places, crédits) relève de la politique d'envoi (T-504).
 */
final class Quote extends AggregateRoot
{
    public const int REASON_MAX = 500;

    private QuoteStatus $status = QuoteStatus::Draft;
    private ?string $number = null;
    /** @var list<QuoteVersion> */
    private array $versions;
    private ?\DateTimeImmutable $sentAt = null;
    private ?\DateTimeImmutable $viewedAt = null;
    private ?\DateTimeImmutable $decidedAt = null;
    private ?string $decisionReason = null;
    private ?\DateTimeImmutable $validUntil = null;
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        private readonly QuoteId $id,
        private readonly string $requestId,
        private readonly string $artisanId,
        QuoteVersion $first,
        private readonly \DateTimeImmutable $createdAt,
    ) {
        $this->versions = [$first];
        $this->updatedAt = $createdAt;
    }

    public static function draft(QuoteId $id, string $requestId, string $artisanId, QuoteContent $content, \DateTimeImmutable $now): self
    {
        return new self($id, $requestId, $artisanId, new QuoteVersion(1, $content, $now), $now);
    }

    /**
     * Reconstitution depuis le stockage (aucun événement).
     *
     * @param non-empty-list<QuoteVersion> $versions
     */
    public static function restore(
        QuoteId $id,
        string $requestId,
        string $artisanId,
        QuoteStatus $status,
        ?string $number,
        array $versions,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $updatedAt,
        ?\DateTimeImmutable $sentAt,
        ?\DateTimeImmutable $viewedAt,
        ?\DateTimeImmutable $decidedAt,
        ?string $decisionReason,
        ?\DateTimeImmutable $validUntil,
    ): self {
        $quote = new self($id, $requestId, $artisanId, $versions[0], $createdAt);
        $quote->versions = $versions;
        $quote->status = $status;
        $quote->number = $number;
        $quote->updatedAt = $updatedAt;
        $quote->sentAt = $sentAt;
        $quote->viewedAt = $viewedAt;
        $quote->decidedAt = $decidedAt;
        $quote->decisionReason = $decisionReason;
        $quote->validUntil = $validUntil;

        return $quote;
    }

    /** Brouillon enregistré (automatiquement ou non) : remplace le contenu de la v1 non envoyée. */
    public function updateDraft(QuoteContent $content, \DateTimeImmutable $now): void
    {
        $this->guard(QuoteStatus::Draft, 'Ce devis est déjà envoyé : révisez-le plutôt (nouvelle version).');
        $this->versions = [new QuoteVersion(1, $content, $this->versions[0]->createdAt)];
        $this->updatedAt = $now;
    }

    /**
     * Envoi de la v1 : numéro attribué, validité démarrée, PDF figé (PR04.4). Appelé par la politique d'envoi atomique
     * (RG-101, T-504).
     */
    public function send(string $number, \DateTimeImmutable $now, ?QuotePdf $pdf = null): void
    {
        $this->guard(QuoteStatus::Draft, 'Ce devis est déjà envoyé.');
        $content = $this->current()->content;
        $content->assertSendable();
        $this->number = $number;
        $this->versions = [$this->versions[0]->sent($now, $pdf)];
        $this->sentAt = $now;
        $this->validUntil = $now->modify(\sprintf('+%d days', $content->conditions->validityDays));
        $this->transition(QuoteStatus::Sent, null, $now);
    }

    /** RG-102 — révision : nouvelle version envoyée, gratuite, sans place supplémentaire ; la validité repart. */
    public function revise(QuoteContent $content, \DateTimeImmutable $now, ?QuotePdf $pdf = null): void
    {
        if (!$this->status->isRevisable()) {
            throw new QuoteRuleViolation('Ce devis ne peut plus être révisé.');
        }
        $content->assertSendable();
        $version = \count($this->versions) + 1;
        $this->versions[] = new QuoteVersion($version, $content, $now, $now, $pdf);
        $this->validUntil = $now->modify(\sprintf('+%d days', $content->conditions->validityDays));
        $this->updatedAt = $now;
        $this->recordEvent(new QuoteRevised($this->id->value, $this->requestId, $this->artisanId, $version, $now));
    }

    /** Première consultation par le client (E-35b) ; sans effet ensuite. */
    public function markViewed(\DateTimeImmutable $now): void
    {
        if (QuoteStatus::Sent !== $this->status) {
            return;
        }
        $this->viewedAt = $now;
        $this->transition(QuoteStatus::Viewed, null, $now);
    }

    public function accept(\DateTimeImmutable $now): void
    {
        $this->decide(QuoteStatus::Accepted, null, $now);
    }

    public function refuse(string $reason, \DateTimeImmutable $now): void
    {
        $reason = trim($reason);
        if ('' === $reason || mb_strlen($reason) > self::REASON_MAX) {
            throw new QuoteRuleViolation('Indiquez le motif du refus.');
        }
        $this->decide(QuoteStatus::Refused, $reason, $now);
    }

    /** Un autre devis a été accepté sur la demande. */
    public function markNotSelected(\DateTimeImmutable $now): void
    {
        $this->decide(QuoteStatus::NotSelected, null, $now);
    }

    /** Validité dépassée sans décision. */
    public function expire(\DateTimeImmutable $now): void
    {
        if (null === $this->validUntil || $now < $this->validUntil) {
            throw new QuoteRuleViolation('Ce devis est encore valable.');
        }
        $this->decide(QuoteStatus::Expired, null, $now);
    }

    /** RG-103 — retrait par l'artisan, avant consultation par le client ; la place se libère (crédit non restitué). */
    public function withdraw(\DateTimeImmutable $now): void
    {
        $this->guard(QuoteStatus::Sent, 'Un devis consulté par le client ne peut plus être retiré.');
        $this->transition(QuoteStatus::Withdrawn, null, $now);
    }

    /** La demande a été annulée par le client ou close par l'équipe. */
    public function cancelWithRequest(\DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo(QuoteStatus::RequestCancelled)) {
            return;
        }
        $this->transition(QuoteStatus::RequestCancelled, null, $now);
    }

    public function id(): QuoteId
    {
        return $this->id;
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function artisanId(): string
    {
        return $this->artisanId;
    }

    public function status(): QuoteStatus
    {
        return $this->status;
    }

    public function number(): ?string
    {
        return $this->number;
    }

    /** Prochain numéro de version (révision RG-102), pour figer son PDF avant l'enregistrement. */
    public function nextVersionNumber(): int
    {
        return QuoteStatus::Draft === $this->status ? 1 : \count($this->versions) + 1;
    }

    public function version(int $number): ?QuoteVersion
    {
        foreach ($this->versions as $version) {
            if ($version->number === $number) {
                return $version;
            }
        }

        return null;
    }

    public function current(): QuoteVersion
    {
        return $this->versions[\count($this->versions) - 1];
    }

    /** @return list<QuoteVersion> */
    public function versions(): array
    {
        return $this->versions;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function sentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function viewedAt(): ?\DateTimeImmutable
    {
        return $this->viewedAt;
    }

    public function decidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function decisionReason(): ?string
    {
        return $this->decisionReason;
    }

    public function validUntil(): ?\DateTimeImmutable
    {
        return $this->validUntil;
    }

    private function decide(QuoteStatus $to, ?string $reason, \DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo($to)) {
            throw new QuoteRuleViolation(\sprintf('Un devis « %s » ne peut pas devenir « %s ».', $this->status->label(), $to->label()));
        }
        $this->decidedAt = $now;
        $this->decisionReason = $reason;
        $this->transition($to, $reason, $now);
    }

    private function guard(QuoteStatus $expected, string $message): void
    {
        if ($expected !== $this->status) {
            throw new QuoteRuleViolation($message);
        }
    }

    private function transition(QuoteStatus $to, ?string $reason, \DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo($to)) {
            throw new QuoteRuleViolation(\sprintf('Un devis « %s » ne peut pas devenir « %s ».', $this->status->label(), $to->label()));
        }
        $from = $this->status;
        $this->status = $to;
        $this->updatedAt = $now;
        $this->recordEvent(new QuoteStatusChanged($this->id->value, $this->requestId, $this->artisanId, $from->value, $to->value, $reason, $now));
    }
}
