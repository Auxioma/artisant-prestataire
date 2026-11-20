<?php

declare(strict_types=1);

namespace App\Content\Application\Page;

/** Page (BO10.2) ; blocs : list<array{id: string, type: string, data: array<string, mixed>}>. */
final readonly class PageView
{
    /**
     * @param list<array{id: string, type: string, data: array<string, mixed>}>      $draft
     * @param list<array{id: string, type: string, data: array<string, mixed>}>|null $live
     * @param list<array{id: string, type: string, data: array<string, mixed>}>|null $pending
     */
    public function __construct(
        public string $id,
        public string $slug,
        public string $title,
        public ?string $metaTitle,
        public ?string $metaDescription,
        public bool $noindex,
        public array $draft,
        public ?array $live,
        public ?\DateTimeImmutable $liveAt,
        public ?array $pending,
        public ?\DateTimeImmutable $pendingAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * Version visible à `$now` (une version programmée l'emporte dès son heure).
     *
     * @return list<array{id: string, type: string, data: array<string, mixed>}>|null
     */
    public function visible(\DateTimeImmutable $now): ?array
    {
        return null !== $this->pending && null !== $this->pendingAt && $this->pendingAt <= $now ? $this->pending : $this->live;
    }

    public function status(\DateTimeImmutable $now): string
    {
        if (null !== $this->pending && null !== $this->pendingAt && $this->pendingAt > $now) {
            return 'scheduled';
        }

        return null === $this->visible($now) ? 'draft' : 'published';
    }

    /** Le brouillon diffère de la version en ligne (modifications non publiées). */
    public function hasUnpublishedChanges(\DateTimeImmutable $now): bool
    {
        return $this->draft !== ($this->pending ?? $this->visible($now));
    }
}
