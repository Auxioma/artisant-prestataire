<?php

declare(strict_types=1);

namespace App\Content\Application\Blog;

/**
 * P15 / BO10.3 — article du blog. Statut déduit de la date de publication : brouillon (aucune), programmé (à venir),
 * publié (passée). Le contenu est un HTML assaini à l'enregistrement.
 */
final readonly class BlogArticle
{
    private const int WORDS_PER_MINUTE = 200;

    /** @param list<string> $jobIds métiers liés (encart « Trouvez un {métier} » et pastilles « Métiers liés ») */
    public function __construct(
        public string $id,
        public string $slug,
        public string $title,
        public ?string $shortTitle,
        public ?string $excerpt,
        public ?BlogCategory $category,
        public ?string $authorName,
        public ?string $authorMediaId,
        public ?string $coverMediaId,
        public string $html,
        public array $jobIds,
        public bool $featured,
        public ?string $metaTitle,
        public ?string $metaDescription,
        public ?\DateTimeImmutable $publishedAt,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }

    public function status(\DateTimeImmutable $now): string
    {
        return match (true) {
            null === $this->publishedAt => 'draft',
            $this->publishedAt > $now => 'scheduled',
            default => 'published',
        };
    }

    public function isVisible(\DateTimeImmutable $now): bool
    {
        return 'published' === $this->status($now);
    }

    /** Temps de lecture affiché (« 5 min de lecture »), une minute au minimum. */
    public function readingMinutes(): int
    {
        $words = preg_split('/\s+/u', trim(strip_tags($this->html)), -1, \PREG_SPLIT_NO_EMPTY);

        return max(1, (int) ceil(\count(\is_array($words) ? $words : []) / self::WORDS_PER_MINUTE));
    }

    /** Mise à jour postérieure à la publication (« mis à jour le … ») : au moins un jour d'écart. */
    public function updatedAfterPublication(): bool
    {
        return null !== $this->publishedAt && $this->updatedAt->format('Y-m-d') > $this->publishedAt->format('Y-m-d');
    }
}
