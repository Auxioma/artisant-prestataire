<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Category;

use App\Catalog\Domain\Event\CategoryChanged;
use App\Catalog\Domain\Event\CategoryCreated;
use App\Catalog\Domain\Event\CategorySlugChanged;
use App\Catalog\Domain\Exception\CatalogRuleViolation;
use App\Catalog\Domain\Label;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\ValueObject\Slug;

/**
 * Nœud de l'arborescence des services (BO06) : catégorie (racine) ou sous-catégorie (enfant d'une catégorie racine).
 */
final class Category extends AggregateRoot
{
    private const string ICON_PATTERN = '/^[a-z][A-Za-z0-9-]{1,30}$/';

    private string $name;
    private ?string $description = null;
    private bool $active = true;
    private bool $featured = false;
    private \DateTimeImmutable $updatedAt;
    private int $version = 1;

    private function __construct(
        private CategoryId $id,
        private ?CategoryId $parentId,
        Label $name,
        private Slug $slug,
        private string $icon,
        private int $position,
        private \DateTimeImmutable $createdAt,
    ) {
        $this->name = $name->value;
        $this->updatedAt = $createdAt;
        self::assertIcon($icon);
    }

    /**
     * @param ?Category $parent catégorie racine pour une sous-catégorie (profondeur maximale : 2)
     */
    public static function create(CategoryId $id, ?self $parent, Label $name, Slug $slug, string $icon, int $position, \DateTimeImmutable $now): self
    {
        if (null !== $parent && !$parent->isRoot()) {
            throw new CatalogRuleViolation('Une sous-catégorie doit être rattachée à une catégorie racine (2 niveaux maximum).');
        }
        $category = new self($id, $parent?->id(), $name, $slug, $icon, $position, $now);
        $category->recordEvent(new CategoryCreated($id->value, $slug->value, $now));

        return $category;
    }

    public function rename(Label $name, Slug $slug, \DateTimeImmutable $now): void
    {
        $previousSlug = $this->slug;
        $this->name = $name->value;
        $this->slug = $slug;
        $this->touch($now, 'renamed');
        if (!$previousSlug->equals($slug)) {
            $this->recordEvent(new CategorySlugChanged($this->id->value, $previousSlug->value, $slug->value, $now));
        }
    }

    public function describe(?string $description, \DateTimeImmutable $now): void
    {
        $description = null === $description ? null : trim($description);
        $this->description = '' === $description ? null : $description;
        $this->touch($now, 'described');
    }

    public function changeIcon(string $icon, \DateTimeImmutable $now): void
    {
        self::assertIcon($icon);
        $this->icon = $icon;
        $this->touch($now, 'icon');
    }

    public function moveTo(int $position, \DateTimeImmutable $now): void
    {
        if ($position < 0) {
            throw new CatalogRuleViolation('Position invalide.');
        }
        $this->position = $position;
        $this->touch($now, 'moved');
    }

    /** P01.5 : « À la une » — seules les catégories actives et mises en avant apparaissent sur l'accueil. */
    public function feature(bool $featured, \DateTimeImmutable $now): void
    {
        $this->featured = $featured;
        $this->touch($now, 'featured');
    }

    public function activate(\DateTimeImmutable $now): void
    {
        $this->active = true;
        $this->touch($now, 'activated');
    }

    /** P01.1 : une catégorie désactivée disparaît du méga-menu et des listes. */
    public function deactivate(\DateTimeImmutable $now): void
    {
        $this->active = false;
        $this->touch($now, 'deactivated');
    }

    public function id(): CategoryId
    {
        return $this->id;
    }

    public function parentId(): ?CategoryId
    {
        return $this->parentId;
    }

    public function isRoot(): bool
    {
        return null === $this->parentId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function slug(): Slug
    {
        return $this->slug;
    }

    public function icon(): string
    {
        return $this->icon;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function isFeatured(): bool
    {
        return $this->featured;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    private function touch(\DateTimeImmutable $now, string $change): void
    {
        $this->updatedAt = $now;
        $this->recordEvent(new CategoryChanged($this->id->value, $change, $now));
    }

    private static function assertIcon(string $icon): void
    {
        if (1 !== preg_match(self::ICON_PATTERN, $icon)) {
            throw new CatalogRuleViolation('Pictogramme invalide (nom d’icône du sprite attendu).');
        }
    }
}
