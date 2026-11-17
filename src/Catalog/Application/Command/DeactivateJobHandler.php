<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

use App\Catalog\Application\CatalogAudit;
use App\Catalog\Application\Port\JobUsageCounter;
use App\Catalog\Domain\Exception\CatalogRuleViolation;
use App\Catalog\Domain\Job\JobRepository;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Domain\Time\Clock;
use App\Shared\Domain\ValueObject\Slug;

/** BO06 : désactivation impossible sans remplacement si le métier est utilisé (assistant de migration). */
final readonly class DeactivateJobHandler implements CommandHandler
{
    public function __construct(
        private JobRepository $jobs,
        private JobUsageCounter $usage,
        private EventBus $events,
        private Clock $clock,
        private CatalogAudit $audit,
    ) {
    }

    public function __invoke(DeactivateJob $command): void
    {
        $job = $this->jobs->findBySlug(new Slug($command->slug))
            ?? throw new CatalogRuleViolation(\sprintf('Métier « %s » introuvable.', $command->slug));
        $replacement = null;
        if (null !== $command->replacementSlug) {
            $replacement = $this->jobs->findBySlug(new Slug($command->replacementSlug))
                ?? throw new CatalogRuleViolation(\sprintf('Métier de remplacement « %s » introuvable.', $command->replacementSlug));
        }
        $usage = $this->usage->count($job->id());
        $job->deactivate($replacement, $usage, $this->clock->now());

        $this->events->publish(...$job->releaseEvents());
        $this->audit->record($command->actorId, 'job_deactivated', 'job', $job->id()->value, ['replacement' => $command->replacementSlug, 'usage' => $usage]);
    }
}
