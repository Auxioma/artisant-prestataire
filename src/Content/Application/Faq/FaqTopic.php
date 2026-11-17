<?php

declare(strict_types=1);

namespace App\Content\Application\Faq;

final readonly class FaqTopic
{
    public function __construct(public string $id, public string $slug, public string $title, public string $description, public string $icon, public int $questionCount)
    {
    }
}
