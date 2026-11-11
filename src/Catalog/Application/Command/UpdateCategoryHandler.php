<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

use App\Catalog\Application\CatalogAudit;
use App\Catalog\Domain\Category\CategoryRepository;
use App\Catalog\Domain\Exception\CatalogRuleViolation;
use App\Catalog\Domain\Label;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Domain\Time\Clock;
use App\Shared\Domain\ValueObject\Slug;

final readonly class UpdateCategoryHandler implements CommandHandler
{
    public function __construct(
        private CategoryRepository $categories,
        private EventBus $events,
        private Clock $clock,
        private CatalogAudit $audit,
    ) {
    }

    public function __invoke(UpdateCategory $command): void
    {
        $category = $this->categories->findBySlug(new Slug($command->slug))
            ?? throw new CatalogRuleViolation(\sprintf('Catégorie « %s » introuvable.', $command->slug));
        $now = $this->clock->now();
        $before = ['slug' => $category->slug()->value, 'name' => $category->name(), 'active' => $category->isActive()];

        if (null !== $command->name || null !== $command->newSlug) {
            $label = new Label($command->name ?? $category->name());
            $slug = null !== $command->newSlug ? new Slug($command->newSlug) : $category->slug();
            $existing = $this->categories->findBySlug($slug);
            if (null !== $existing && !$existing->id()->equals($category->id())) {
                throw new CatalogRuleViolation(\sprintf('Une catégorie utilise déjà le slug « %s ».', $slug->value));
            }
            $category->rename($label, $slug, $now);
        }
        if (null !== $command->icon) {
            $category->changeIcon($command->icon, $now);
        }
        if (null !== $command->featured) {
            $category->feature($command->featured, $now);
        }
        if (null !== $command->position) {
            $category->moveTo($command->position, $now);
        }
        if (true === $command->active) {
            $category->activate($now);
        } elseif (false === $command->active) {
            $category->deactivate($now);
        }

        $this->events->publish(...$category->releaseEvents());
        $this->audit->record($command->actorId, 'category_updated', 'category', $category->id()->value, [
            'before' => json_encode($before, \JSON_THROW_ON_ERROR),
            'after' => json_encode(['slug' => $category->slug()->value, 'name' => $category->name(), 'active' => $category->isActive()], \JSON_THROW_ON_ERROR),
        ]);
    }
}
