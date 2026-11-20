<?php

declare(strict_types=1);

namespace App\Shared\Application\Moderation;

/** Élément de la file BO05.1 : avis à publier, réponse à publier, ou avis signalé par l'artisan. */
final readonly class ReviewModerationItem
{
    /**
     * @param list<string>          $photos
     * @param array<string, string> $proof   devis accepté, date d'acceptation, prestation (preuve de certification RG-033)
     * @param array<string, int>    $history avis déposés et refus antérieurs de l'auteur
     */
    public function __construct(
        public string $id,
        public string $kind,
        public string $artisanName,
        public string $author,
        public int $rating,
        public ?string $title,
        public string $text,
        public array $photos,
        public ?string $reply,
        public ?string $reportReason,
        public int $riskScore,
        public array $proof,
        public array $history,
        public ?string $requestId,
        public \DateTimeImmutable $since,
    ) {
    }

    public function kindLabel(): string
    {
        return ['review' => 'Avis à modérer', 'reply' => 'Réponse à modérer', 'report' => 'Avis signalé'][$this->kind] ?? $this->kind;
    }
}
