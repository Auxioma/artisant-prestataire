<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Signup;

use App\ArtisanProfile\Domain\Profile\ArtisanProfileRepository;
use App\ArtisanProfile\Domain\Profile\ArtisanRuleViolation;
use App\Catalog\Application\Query\JobIndexEntries;
use App\Catalog\Application\Query\JobIndexEntry;
use App\Catalog\Domain\Job\JobId;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Time\Clock;

final readonly class ChooseJobsHandler implements CommandHandler
{
    public function __construct(
        private ArtisanProfileRepository $profiles,
        private QueryBus $queries,
        private SettingsProvider $settings,
        private EventBus $events,
        private Clock $clock,
    ) {
    }

    public function __invoke(ChooseJobs $command): void
    {
        $profile = $this->profiles->findByUserId($command->userId) ?? throw new ArtisanRuleViolation('Déclarez d’abord votre entreprise.');
        $ids = array_values(array_unique(array_filter($command->jobIds, static fn (string $id): bool => 1 === preg_match('/^[0-9a-f-]{36}$/', $id))));

        // Seuls des métiers du référentiel, actifs et proposés au public, peuvent être choisis.
        $entries = [] === $ids ? [] : $this->queries->ask(new JobIndexEntries($ids));
        $searchable = array_map(static fn (JobIndexEntry $e): string => $e->id, array_filter(\is_array($entries) ? $entries : [], static fn (mixed $e): bool => $e instanceof JobIndexEntry && $e->searchable));
        if (\count($searchable) !== \count($ids)) {
            throw new SignupRejected('jobs', SignupRejected::JOBS_INVALID, 'Un des métiers choisis n’est plus proposé.');
        }

        $profile->chooseJobs(array_map(static fn (string $id): JobId => new JobId($id), $ids), new JobId($command->mainJobId), $this->settings->int(SettingKey::ProMaxJobs), $this->clock->now());
        $this->events->publish(...$profile->releaseEvents());
    }
}
