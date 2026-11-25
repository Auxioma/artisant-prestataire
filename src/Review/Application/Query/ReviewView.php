<?php

declare(strict_types=1);

namespace App\Review\Application\Query;

/** Avis lu (C06 client, PR09 artisan, P10.9 fiche, BO05.1). */
final readonly class ReviewView
{
    /**
     * @param array<string, int> $subRatings
     * @param list<string>       $photos     photos disponibles (analysées)
     */
    public function __construct(
        public string $id,
        public string $requestId,
        public string $requestNumber,
        public ?string $requestTitle,
        public string $artisanId,
        public string $artisanName,
        public string $clientFirstName,
        public ?string $clientLastInitial,
        public ?string $city,
        public int $rating,
        public array $subRatings,
        public ?string $title,
        public string $text,
        public \DateTimeImmutable $serviceDate,
        public ?bool $recommends,
        public array $photos,
        public string $status,
        public int $riskScore,
        public ?string $reasonCode,
        public ?string $moderatorNote,
        public ?string $reply,
        public ?string $replyStatus,
        public ?\DateTimeImmutable $repliedAt,
        public ?string $replyReason,
        public ?\DateTimeImmutable $reportedAt,
        public ?string $reportReason,
        public \DateTimeImmutable $createdAt,
        public string $clientUserId = '',
    ) {
    }

    /** « Camille D. » (P10.9 : prénom et initiale). */
    public function author(): string
    {
        return $this->clientFirstName.(null !== $this->clientLastInitial ? ' '.$this->clientLastInitial.'.' : '');
    }

    public function statusLabel(): string
    {
        return \App\Review\Domain\Review\ReviewStatus::from($this->status)->label();
    }

    public function reasonLabel(): ?string
    {
        return null === $this->reasonCode ? null : \App\Review\Domain\Review\RejectionReason::tryFrom($this->reasonCode)?->label();
    }

    public function replyReasonLabel(): ?string
    {
        return null === $this->replyReason ? null : \App\Review\Domain\Review\RejectionReason::tryFrom($this->replyReason)?->label();
    }
}
