<?php

declare(strict_types=1);

namespace App\Billing\Domain\Plan;

use App\Billing\Domain\Plan\Event\PlanChanged;
use App\Shared\Domain\AggregateRoot;

/**
 * BO08.1 — forfait d'abonnement artisan. Brouillon à la création, publié quand il est vendable (prix mensuel et volume
 * de devis, ou illimité avec plafond — Q5), archivé ensuite (jamais supprimé une fois publié). Les prix sont versionnés :
 * un nouveau montant remplace le prix actif sans toucher aux abonnés existants (nouveau Price Stripe).
 */
final class Plan extends AggregateRoot
{
    private PlanStatus $status = PlanStatus::Draft;
    /** @var array<string, PlanPrice> prix actifs par périodicité */
    private array $prices = [];
    private ?\DateTimeImmutable $publishedAt = null;
    private bool $everPublished = false;
    private bool $pendingChange = false;

    private function __construct(
        private readonly PlanId $id,
        private string $slug,
        private PlanTerms $terms,
        private int $position,
        private readonly \DateTimeImmutable $createdAt,
        private \DateTimeImmutable $updatedAt,
        private ?string $stripeProductId = null,
    ) {
    }

    public static function draft(PlanId $id, string $slug, PlanTerms $terms, int $position, \DateTimeImmutable $now): self
    {
        if (1 !== preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            throw new PlanRuleViolation('Identifiant d’URL invalide (minuscules, chiffres et tirets).');
        }

        return new self($id, $slug, $terms, $position, $now, $now);
    }

    /**
     * Reconstitution depuis le stockage.
     *
     * @param array<string, PlanPrice> $prices
     */
    public static function restore(PlanId $id, string $slug, PlanTerms $terms, int $position, PlanStatus $status, array $prices, ?\DateTimeImmutable $publishedAt, bool $everPublished, ?string $stripeProductId, \DateTimeImmutable $createdAt, \DateTimeImmutable $updatedAt): self
    {
        $plan = new self($id, $slug, $terms, $position, $createdAt, $updatedAt, $stripeProductId);
        $plan->status = $status;
        $plan->prices = $prices;
        $plan->publishedAt = $publishedAt;
        $plan->everPublished = $everPublished;

        return $plan;
    }

    public function revise(PlanTerms $terms, \DateTimeImmutable $now): void
    {
        $this->guardNotArchived();
        if (PlanStatus::Published === $this->status && !$terms->isSellable()) {
            throw new PlanRuleViolation('Un forfait publié doit garder un volume de devis (ou illimité avec plafond).');
        }
        $this->terms = $terms;
        $this->changed($now);
    }

    /** Nouveau prix (ou retrait, null) pour une périodicité ; le prix précédent reste dans l'historique. */
    public function changePrice(BillingInterval $interval, ?int $amountCents, string $priceId, \DateTimeImmutable $now): void
    {
        $this->guardNotArchived();
        $current = $this->prices[$interval->value] ?? null;
        if ($current?->amountCents === $amountCents) {
            return;
        }
        if (null === $amountCents) {
            if (BillingInterval::Month === $interval && PlanStatus::Published === $this->status) {
                throw new PlanRuleViolation('Un forfait publié garde un prix mensuel.');
            }
            unset($this->prices[$interval->value]);
        } else {
            $this->prices[$interval->value] = new PlanPrice($priceId, $interval, $amountCents, $now);
        }
        $this->changed($now);
    }

    public function publish(\DateTimeImmutable $now): void
    {
        $this->guardNotArchived();
        if (null === $this->price(BillingInterval::Month)) {
            throw new PlanRuleViolation('Fixez le prix HT mensuel avant de publier ce forfait.');
        }
        if (!$this->terms->isSellable()) {
            throw new PlanRuleViolation('Indiquez le nombre de devis par période, ou un plafond d’usage raisonnable pour un forfait illimité.');
        }
        $this->status = PlanStatus::Published;
        $this->publishedAt ??= $now;
        $this->everPublished = true;
        $this->changed($now);
    }

    /** Retiré de la vente ; les abonnés existants le conservent (BO08.1). */
    public function archive(\DateTimeImmutable $now): void
    {
        $this->guardNotArchived();
        $this->status = PlanStatus::Archived;
        $this->changed($now);
    }

    /** Supprimable seulement s'il n'a jamais été publié ni souscrit (sinon : archiver). */
    public function assertDeletable(int $subscribers): void
    {
        if ($this->everPublished || $subscribers > 0) {
            throw new PlanRuleViolation('Un forfait déjà publié ou souscrit ne peut pas être supprimé : archivez-le.');
        }
    }

    public function moveTo(int $position, \DateTimeImmutable $now): void
    {
        $this->position = max(0, $position);
        $this->updatedAt = $now;
    }

    public function linkStripeProduct(string $productId): void
    {
        $this->stripeProductId = $productId;
    }

    public function price(BillingInterval $interval): ?PlanPrice
    {
        return $this->prices[$interval->value] ?? null;
    }

    /** @return array<string, PlanPrice> */
    public function prices(): array
    {
        return $this->prices;
    }

    public function id(): PlanId
    {
        return $this->id;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function terms(): PlanTerms
    {
        return $this->terms;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function status(): PlanStatus
    {
        return $this->status;
    }

    public function publishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function everPublished(): bool
    {
        return $this->everPublished;
    }

    public function stripeProductId(): ?string
    {
        return $this->stripeProductId;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** Un seul événement par opération, même si plusieurs champs changent. */
    private function changed(\DateTimeImmutable $now): void
    {
        $this->updatedAt = $now;
        if (!$this->pendingChange) {
            $this->pendingChange = true;
            $this->recordEvent(new PlanChanged($this->id->value, $now));
        }
    }

    private function guardNotArchived(): void
    {
        if (PlanStatus::Archived === $this->status) {
            throw new PlanRuleViolation('Ce forfait est archivé : il ne se modifie plus.');
        }
    }
}
