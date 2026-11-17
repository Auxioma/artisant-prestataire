<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

use App\Catalog\Application\CatalogAudit;
use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Category\CategoryRepository;
use App\Catalog\Domain\Exception\CatalogRuleViolation;
use App\Catalog\Domain\Label;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;
use App\Shared\Domain\ValueObject\Slug;

final readonly class CreateCategoryHandler implements CommandHandler
{
    public function __construct(
        private CategoryRepository $categories,
        private EventBus $events,
        private IdGenerator $ids,
        private Clock $clock,
        private CatalogAudit $audit,
    ) {
    }

    public function __invoke(CreateCategory $command): void
    {
        $label = new Label($command->name);
        $slug = null !== $command->slug ? new Slug($command->slug) : Slug::fromText($label->value);
        if (null !== $this->categories->findBySlug($slug)) {
            throw new CatalogRuleViolation(\sprintf('Une catégorie utilise déjà le slug « %s ».', $slug->value));
        }
        $parent = null;
        if (null !== $command->parentSlug) {
            $parent = $this->categories->findBySlug(new Slug($command->parentSlug))
                ?? throw new CatalogRuleViolation(\sprintf('Catégorie parente « %s » introuvable.', $command->parentSlug));
        }
        $now = $this->clock->now();
        $category = Category::create(new CategoryId($this->ids->next()), $parent, $label, $slug, $command->icon, $this->categories->nextPosition($parent?->id()), $now);
        $category->feature($command->featured, $now);
        $category->describe($command->description, $now);

        $this->categories->add($category);
        $this->events->publish(...$category->releaseEvents());
        $this->audit->record($command->actorId, 'category_created', 'category', $category->id()->value, ['slug' => $slug->value, 'parent' => $command->parentSlug]);
    }
}
