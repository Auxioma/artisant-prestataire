<?php

declare(strict_types=1);

namespace App\Catalog\Application\Questionnaire;

use App\Catalog\Domain\Questionnaire\QuestionnaireSchema;

final readonly class JobQuestionnaireView
{
    public function __construct(public string $jobId, public int $version, public QuestionnaireSchema $schema)
    {
    }
}
