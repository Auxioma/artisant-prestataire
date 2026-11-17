<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Questionnaire;

/** Réponses refusées : un message par champ, à afficher à côté de la question. */
final class InvalidAnswers extends \DomainException
{
    /** @param array<string, string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Certaines réponses sont incomplètes ou invalides.');
    }
}
