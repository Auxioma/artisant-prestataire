<?php

declare(strict_types=1);

namespace App\Catalog\Application\Questionnaire;

use App\Shared\Application\Bus\Query;

/** P11.3 — questionnaire actif d'un métier. Réponse : ?JobQuestionnaireView (null : aucune question spécifique). */
final readonly class JobQuestionnaire implements Query
{
    public function __construct(public string $jobId)
    {
    }
}
