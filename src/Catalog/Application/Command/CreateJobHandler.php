<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

use App\Catalog\Application\CatalogAudit;
use App\Catalog\Application\JobSlugPolicy;
use App\Catalog\Domain\Category\CategoryRepository;
use App\Catalog\Domain\Exception\CatalogRuleViolation;
use App\Catalog\Domain\Job\Job;
use App\Catalog\Domain\Job\JobId;
use App\Catalog\Domain\Job\JobRepository;
use App\Catalog\Domain\Label;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;
use App\Shared\Domain\ValueObject\Slug;

final readonly class CreateJobHandler implements CommandHandler
{
    public function __construct(
        private CategoryRepository $categories,
        private JobRepository $jobs,
        private EventBus $events,
        private IdGenerator $ids,
        private Clock $clock,
        private CatalogAudit $audit,
    ) {
    }

    public function __invoke(CreateJob $command): void
    {
        $category = $this->categories->findBySlug(new Slug($command->categorySlug))
            ?? throw new CatalogRuleViolation(\sprintf('Catégorie « %s » introuvable.', $command->categorySlug));
        $label = new Label($command->name);
        $slug = null !== $command->slug ? new Slug($command->slug) : Slug::fromText($label->value);
        JobSlugPolicy::assertAllowed($slug);
        if (null !== $this->jobs->findBySlug($slug)) {
            throw new CatalogRuleViolation(\sprintf('Un métier utilise déjà le slug « %s ».', $slug->value));
        }
        $now = $this->clock->now();
        $job = Job::create(new JobId($this->ids->next()), $category->id(), $label, $slug, $this->jobs->nextPosition($category->id()), $now);
        $job->replaceSynonyms($command->synonyms, $now);
        $job->requireDocuments($command->requiredDocuments, $command->insuranceRequired, $command->decennialRequired, $now);

        $this->jobs->add($job);
        $this->events->publish(...$job->releaseEvents());
        $this->audit->record($command->actorId, 'job_created', 'job', $job->id()->value, ['slug' => $slug->value, 'category' => $category->slug()->value]);
    }
}
