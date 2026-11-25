<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

use App\Catalog\Application\CatalogAudit;
use App\Catalog\Application\JobSlugPolicy;
use App\Catalog\Domain\Category\CategoryRepository;
use App\Catalog\Domain\Exception\CatalogRuleViolation;
use App\Catalog\Domain\Job\JobRepository;
use App\Catalog\Domain\Label;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Domain\Time\Clock;
use App\Shared\Domain\ValueObject\Slug;

final readonly class UpdateJobHandler implements CommandHandler
{
    public function __construct(
        private CategoryRepository $categories,
        private JobRepository $jobs,
        private EventBus $events,
        private Clock $clock,
        private CatalogAudit $audit,
    ) {
    }

    public function __invoke(UpdateJob $command): void
    {
        $job = $this->jobs->findBySlug(new Slug($command->slug))
            ?? throw new CatalogRuleViolation(\sprintf('Métier « %s » introuvable.', $command->slug));
        $now = $this->clock->now();
        $previousSlug = $job->slug()->value;

        if (null !== $command->name || null !== $command->newSlug) {
            $label = new Label($command->name ?? $job->name());
            $slug = null !== $command->newSlug ? new Slug($command->newSlug) : $job->slug();
            JobSlugPolicy::assertAllowed($slug);
            $existing = $this->jobs->findBySlug($slug);
            if (null !== $existing && !$existing->id()->equals($job->id())) {
                throw new CatalogRuleViolation(\sprintf('Un métier utilise déjà le slug « %s ».', $slug->value));
            }
            $job->rename($label, $slug, $now);
        }
        if (null !== $command->categorySlug) {
            $category = $this->categories->findBySlug(new Slug($command->categorySlug))
                ?? throw new CatalogRuleViolation(\sprintf('Catégorie « %s » introuvable.', $command->categorySlug));
            $job->moveToCategory($category->id(), $this->jobs->nextPosition($category->id()), $now);
        }
        if (null !== $command->synonyms) {
            $job->replaceSynonyms($command->synonyms, $now);
        }
        if (null !== $command->position) {
            $job->moveTo($command->position, $now);
        }
        if (true === $command->activate && !$job->isActive()) {
            $job->activate($now);
        }

        $this->events->publish(...$job->releaseEvents());
        $this->audit->record($command->actorId, 'job_updated', 'job', $job->id()->value, ['slug_before' => $previousSlug, 'slug_after' => $job->slug()->value]);
    }
}
