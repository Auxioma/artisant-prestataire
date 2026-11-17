<?php

declare(strict_types=1);

namespace App\Catalog\Application\Questionnaire;

use App\Shared\Application\Bus\QueryHandler;

final readonly class JobQuestionnaireHandler implements QueryHandler
{
    public function __construct(private QuestionnaireStore $store)
    {
    }

    public function __invoke(JobQuestionnaire $query): ?JobQuestionnaireView
    {
        return $this->store->active($query->jobId);
    }
}
