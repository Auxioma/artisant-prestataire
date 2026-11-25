<?php

declare(strict_types=1);

namespace App\Review\Domain\Review;

use App\Review\Domain\Review\Event\ReviewChanged;
use App\Shared\Domain\AggregateRoot;

/**
 * Avis certifié (RG-033) d'un client sur l'artisan dont il a accepté le devis : un par demande. Modération a priori ou
 * a posteriori et score de risque (RG-035) ; modifiable pendant `reviews.edit_days` tant que l'artisan n'a pas répondu ;
 * une réponse publique de l'artisan (1 500 caractères, modérée, RG-036) ; signalement par l'artisan (l'avis reste en
 * ligne pendant l'examen).
 */
final class Review extends AggregateRoot
{
    public const int REPLY_MAX = 1500;
    public const int REPORT_MAX = 300;

    private function __construct(
        private readonly ReviewId $id,
        private readonly string $requestId,
        private readonly string $quoteId,
        private readonly string $clientUserId,
        private readonly string $artisanId,
        private ReviewContent $content,
        private ReviewStatus $status,
        private int $riskScore,
        private ?RejectionReason $reason,
        private ?string $moderatorNote,
        private ?string $moderatedBy,
        private ?\DateTimeImmutable $moderatedAt,
        private ?string $reply,
        private ?ReviewStatus $replyStatus,
        private ?\DateTimeImmutable $repliedAt,
        private ?RejectionReason $replyReason,
        private ?\DateTimeImmutable $reportedAt,
        private ?string $reportReason,
        private readonly \DateTimeImmutable $createdAt,
        private \DateTimeImmutable $updatedAt,
    ) {
    }

    /** `$review` : vrai si l'avis doit passer en modération (mode a priori ou score au-delà du seuil). */
    public static function submit(ReviewId $id, string $requestId, string $quoteId, string $clientUserId, string $artisanId, ReviewContent $content, int $riskScore, bool $review, \DateTimeImmutable $now): self
    {
        $r = new self($id, $requestId, $quoteId, $clientUserId, $artisanId, $content, $review ? ReviewStatus::Pending : ReviewStatus::Published, $riskScore, null, null, null, null, null, null, null, null, null, null, $now, $now);
        $r->record($review ? 'submitted' : 'published', null, $now);

        return $r;
    }

    /** @internal persistance */
    public static function restore(ReviewId $id, string $requestId, string $quoteId, string $clientUserId, string $artisanId, ReviewContent $content, ReviewStatus $status, int $riskScore, ?RejectionReason $reason, ?string $moderatorNote, ?string $moderatedBy, ?\DateTimeImmutable $moderatedAt, ?string $reply, ?ReviewStatus $replyStatus, ?\DateTimeImmutable $repliedAt, ?RejectionReason $replyReason, ?\DateTimeImmutable $reportedAt, ?string $reportReason, \DateTimeImmutable $createdAt, \DateTimeImmutable $updatedAt): self
    {
        return new self($id, $requestId, $quoteId, $clientUserId, $artisanId, $content, $status, $riskScore, $reason, $moderatorNote, $moderatedBy, $moderatedAt, $reply, $replyStatus, $repliedAt, $replyReason, $reportedAt, $reportReason, $createdAt, $updatedAt);
    }

    /** C06 — modification par l'auteur : pendant `$editDays` jours, ou à la demande de la modération, et avant toute réponse. */
    public function edit(string $clientUserId, ReviewContent $content, int $riskScore, bool $review, int $editDays, \DateTimeImmutable $now): void
    {
        $this->guardAuthor($clientUserId);
        if (null !== $this->reply) {
            throw new ReviewRuleViolation('Le professionnel a répondu à votre avis : il ne peut plus être modifié.');
        }
        if (ReviewStatus::ChangesRequested !== $this->status && $now > $this->createdAt->modify(\sprintf('+%d days', $editDays))) {
            throw new ReviewRuleViolation(\sprintf('Un avis se modifie pendant %d jours après son dépôt.', $editDays));
        }
        if (ReviewStatus::Hidden === $this->status) {
            throw new ReviewRuleViolation('Cet avis a été masqué par la modération.');
        }
        $this->content = $content;
        $this->riskScore = $riskScore;
        $this->status = $review ? ReviewStatus::Pending : ReviewStatus::Published;
        $this->reason = null;
        $this->moderatorNote = null;
        $this->updatedAt = $now;
        $this->record($review ? 'edited' : 'published', null, $now);
    }

    public function publish(string $adminId, \DateTimeImmutable $now): void
    {
        $this->moderate(ReviewStatus::Published, null, null, $adminId, $now);
    }

    public function reject(string $adminId, RejectionReason $reason, \DateTimeImmutable $now): void
    {
        $this->moderate(ReviewStatus::Rejected, $reason, null, $adminId, $now);
    }

    public function hide(string $adminId, RejectionReason $reason, \DateTimeImmutable $now): void
    {
        $this->moderate(ReviewStatus::Hidden, $reason, null, $adminId, $now);
    }

    public function requestChanges(string $adminId, string $note, \DateTimeImmutable $now): void
    {
        if ('' === trim($note)) {
            throw new ReviewRuleViolation('Indiquez à l’auteur ce qu’il doit modifier.');
        }
        $this->moderate(ReviewStatus::ChangesRequested, null, trim($note), $adminId, $now);
    }

    /** PR09.2 — une réponse publique par avis publié, modifiable pendant `$editDays` jours ; modérée comme un avis (RG-036). */
    public function answer(string $artisanId, string $text, bool $review, int $editDays, \DateTimeImmutable $now): void
    {
        if ($artisanId !== $this->artisanId) {
            throw new ReviewRuleViolation('Avis introuvable.');
        }
        if (ReviewStatus::Published !== $this->status) {
            throw new ReviewRuleViolation('Vous pouvez répondre à un avis publié.');
        }
        $text = trim($text);
        if ('' === $text || mb_strlen($text) > self::REPLY_MAX) {
            throw new ReviewRuleViolation(\sprintf('Votre réponse doit contenir de 1 à %d caractères.', self::REPLY_MAX));
        }
        if (null !== $this->repliedAt && $now > $this->repliedAt->modify(\sprintf('+%d days', $editDays))) {
            throw new ReviewRuleViolation(\sprintf('Une réponse se modifie pendant %d jours.', $editDays));
        }
        $this->repliedAt ??= $now;
        $this->reply = $text;
        $this->replyReason = null;
        $this->replyStatus = $review ? ReviewStatus::Pending : ReviewStatus::Published;
        $this->updatedAt = $now;
        $this->record($review ? 'reply_submitted' : 'reply_published', null, $now);
    }

    public function moderateReply(string $adminId, ?RejectionReason $reason, \DateTimeImmutable $now): void
    {
        if (ReviewStatus::Pending !== $this->replyStatus) {
            throw new ReviewRuleViolation('Aucune réponse en attente de modération.');
        }
        $this->replyStatus = null === $reason ? ReviewStatus::Published : ReviewStatus::Rejected;
        $this->replyReason = $reason;
        $this->moderatedBy = $adminId;
        $this->moderatedAt = $now;
        $this->updatedAt = $now;
        $this->record(null === $reason ? 'reply_published' : 'reply_rejected', $reason?->label(), $now);
    }

    /** RG-036 — signalement par l'artisan : l'avis reste en ligne pendant l'examen. */
    public function report(string $artisanId, string $reason, \DateTimeImmutable $now): void
    {
        if ($artisanId !== $this->artisanId) {
            throw new ReviewRuleViolation('Avis introuvable.');
        }
        $reason = trim($reason);
        if ('' === $reason || mb_strlen($reason) > self::REPORT_MAX) {
            throw new ReviewRuleViolation('Indiquez le motif du signalement.');
        }
        if (null !== $this->reportedAt) {
            throw new ReviewRuleViolation('Cet avis est déjà signalé : notre équipe l’examine.');
        }
        $this->reportedAt = $now;
        $this->reportReason = $reason;
        $this->updatedAt = $now;
        $this->record('reported', $reason, $now);
    }

    public function guardAuthor(string $clientUserId): void
    {
        if ($clientUserId !== $this->clientUserId) {
            throw new ReviewRuleViolation('Avis introuvable.');
        }
    }

    private function moderate(ReviewStatus $to, ?RejectionReason $reason, ?string $note, string $adminId, \DateTimeImmutable $now): void
    {
        $this->status = $to;
        $this->reason = $reason;
        $this->moderatorNote = $note;
        $this->moderatedBy = $adminId;
        $this->moderatedAt = $now;
        $this->reportedAt = ReviewStatus::Published === $to ? null : $this->reportedAt;
        $this->reportReason = ReviewStatus::Published === $to ? null : $this->reportReason;
        $this->updatedAt = $now;
        $this->record(['published' => 'published', 'rejected' => 'rejected', 'hidden' => 'hidden', 'changes_requested' => 'changes_requested'][$to->value] ?? $to->value, $reason?->label() ?? $note, $now);
    }

    private function record(string $change, ?string $reason, \DateTimeImmutable $now): void
    {
        $this->recordEvent(new ReviewChanged($this->id->value, $this->artisanId, $change, $reason, $now));
    }

    public function id(): ReviewId
    {
        return $this->id;
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function quoteId(): string
    {
        return $this->quoteId;
    }

    public function clientUserId(): string
    {
        return $this->clientUserId;
    }

    public function artisanId(): string
    {
        return $this->artisanId;
    }

    public function content(): ReviewContent
    {
        return $this->content;
    }

    public function status(): ReviewStatus
    {
        return $this->status;
    }

    public function riskScore(): int
    {
        return $this->riskScore;
    }

    public function reason(): ?RejectionReason
    {
        return $this->reason;
    }

    public function moderatorNote(): ?string
    {
        return $this->moderatorNote;
    }

    public function moderatedBy(): ?string
    {
        return $this->moderatedBy;
    }

    public function moderatedAt(): ?\DateTimeImmutable
    {
        return $this->moderatedAt;
    }

    public function reply(): ?string
    {
        return $this->reply;
    }

    public function replyStatus(): ?ReviewStatus
    {
        return $this->replyStatus;
    }

    public function repliedAt(): ?\DateTimeImmutable
    {
        return $this->repliedAt;
    }

    public function replyReason(): ?RejectionReason
    {
        return $this->replyReason;
    }

    public function reportedAt(): ?\DateTimeImmutable
    {
        return $this->reportedAt;
    }

    public function reportReason(): ?string
    {
        return $this->reportReason;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
