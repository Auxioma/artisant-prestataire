<?php

declare(strict_types=1);

namespace App\Content\Application\Label;

use App\Shared\Application\Bus\QueryHandler;

final readonly class LabelQueryHandler implements QueryHandler
{
    public function __construct(private LabelStore $labels)
    {
    }

    /** @return list<UiLabel>|array<string, string> */
    public function __invoke(LabelQuery $query): array
    {
        return $query->overridesOnly ? $this->labels->overrides($query->locale) : $this->labels->all($query->locale);
    }
}
