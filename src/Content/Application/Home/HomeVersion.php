<?php

declare(strict_types=1);

namespace App\Content\Application\Home;

/**
 * Une version de la page d'accueil : blocs ordonnés, état, auteur.
 *
 * @phpstan-type Block array{type: string, enabled: bool, settings: array<string, mixed>}
 */
final readonly class HomeVersion
{
    /**
     * @param list<Block> $blocks
     */
    public function __construct(
        public string $id,
        public int $number,
        public array $blocks,
        public string $authorId,
        public \DateTimeImmutable $updatedAt,
        public ?\DateTimeImmutable $publishAt = null,
        public ?string $publishedBy = null,
    ) {
    }

    /** @param list<Block> $blocks */
    public function withBlocks(array $blocks, string $authorId, \DateTimeImmutable $now): self
    {
        return new self($this->id, $this->number, $blocks, $authorId, $now, $this->publishAt, $this->publishedBy);
    }
}
