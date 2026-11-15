<?php

declare(strict_types=1);

namespace App\Content\Application\Faq\Admin;

use App\Shared\Application\Bus\Query;

/** Réponse : array{topics: list<FaqAdminTopic>, questions: list<FaqAdminQuestion>} (questions de `topicId`). */
final readonly class FaqAdminQuery implements Query
{
    public function __construct(public ?string $topicId = null)
    {
    }
}
