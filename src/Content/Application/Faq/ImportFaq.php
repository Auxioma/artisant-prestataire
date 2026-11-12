<?php

declare(strict_types=1);

namespace App\Content\Application\Faq;

use App\Shared\Application\Bus\Command;

/**
 * Import du contenu de l'aide (fichier YAML) en attendant l'éditeur BO10.4 (T-801) : rubriques et questions créées
 * ou mises à jour par slug, réponses assainies ; les votes sont conservés.
 *
 * @phpstan-type ImportedQuestion array{slug: string, audience: string, question: string, answer: string}
 * @phpstan-type ImportedTopic array{slug: string, title: string, description: string, icon: string, questions: list<ImportedQuestion>}
 */
final readonly class ImportFaq implements Command
{
    /** @param list<ImportedTopic> $topics */
    public function __construct(public array $topics)
    {
    }
}
