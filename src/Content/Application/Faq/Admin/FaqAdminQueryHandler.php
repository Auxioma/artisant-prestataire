<?php

declare(strict_types=1);

namespace App\Content\Application\Faq\Admin;

use App\Shared\Application\Bus\QueryHandler;

final readonly class FaqAdminQueryHandler implements QueryHandler
{
    public function __construct(private FaqAdminStore $store)
    {
    }

    /** @return array{topics: list<FaqAdminTopic>, questions: list<FaqAdminQuestion>} */
    public function __invoke(FaqAdminQuery $query): array
    {
        return ['topics' => $this->store->topics(), 'questions' => null === $query->topicId ? [] : $this->store->questions($query->topicId)];
    }
}
