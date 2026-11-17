<?php

declare(strict_types=1);

namespace App\Catalog\Application\Questionnaire;

use App\Catalog\Domain\Questionnaire\QuestionnaireSchema;

interface QuestionnaireStore
{
    /**
     * Rend actif ce schéma pour le métier : nouvelle version s'il diffère du schéma actif (les demandes déjà envoyées
     * gardent la version avec laquelle elles ont été remplies), sinon rien. Renvoie la version active.
     */
    public function activate(string $jobId, QuestionnaireSchema $schema, \DateTimeImmutable $now): int;

    public function active(string $jobId): ?JobQuestionnaireView;

    public function version(string $jobId, int $version): ?JobQuestionnaireView;
}
