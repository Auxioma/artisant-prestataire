<?php

declare(strict_types=1);

namespace App\Catalog\Application\Questionnaire;

use App\Catalog\Application\Query\JobIndexEntries;
use App\Catalog\Application\Query\JobIndexEntry;
use App\Catalog\Domain\Questionnaire\InvalidQuestionnaire;
use App\Catalog\Domain\Questionnaire\QuestionnaireSchema;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Domain\Time\Clock;

/** Import des questionnaires (tous contrôlés avant écriture : un fichier invalide n'est pas importé à moitié). */
final readonly class QuestionnaireHandler implements CommandHandler
{
    public function __construct(private QuestionnaireStore $store, private QueryBus $queries, private Clock $clock)
    {
    }

    public function __invoke(ImportQuestionnaires $command): void
    {
        $entries = $this->queries->ask(new JobIndexEntries());
        $schemas = [];
        foreach (\is_array($entries) ? $entries : [] as $job) {
            if (!$job instanceof JobIndexEntry) {
                continue;
            }
            $specific = $command->jobs[$job->slug] ?? $command->categories[$job->categorySlug] ?? [];
            try {
                $schemas[$job->id] = QuestionnaireSchema::fromArray(['fields' => [...$command->common, ...$specific]]);
            } catch (InvalidQuestionnaire $e) {
                throw new InvalidQuestionnaire(\sprintf('Métier « %s » : %s', $job->slug, $e->getMessage()));
            }
        }
        $now = $this->clock->now();
        foreach ($schemas as $jobId => $schema) {
            $this->store->activate($jobId, $schema, $now);
        }
    }
}
