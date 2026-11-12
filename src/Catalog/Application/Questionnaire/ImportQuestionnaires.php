<?php

declare(strict_types=1);

namespace App\Catalog\Application\Questionnaire;

use App\Shared\Application\Bus\Command;

/**
 * Questionnaires par métier en attendant le constructeur BO06 : `common` (questions posées pour tous les métiers,
 * placées après celles du métier), `categories` (par slug de catégorie) et `jobs` (par slug de métier, remplace la catégorie).
 */
final readonly class ImportQuestionnaires implements Command
{
    /**
     * @param list<array<string, mixed>>                $common
     * @param array<string, list<array<string, mixed>>> $categories
     * @param array<string, list<array<string, mixed>>> $jobs
     */
    public function __construct(public array $common, public array $categories, public array $jobs)
    {
    }
}
