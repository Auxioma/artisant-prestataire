<?php

declare(strict_types=1);

namespace App\Content\Application\Faq\Admin;

final readonly class FaqAdminTopic
{
    public function __construct(
        public string $id,
        public string $slug,
        public string $title,
        public string $description,
        public string $icon,
        public int $position,
        public bool $active,
        public int $questions,
        public int $helpfulYes,
        public int $helpfulNo,
    ) {
    }

    /** Part de « oui » parmi les votes (null sans vote). */
    public function helpfulRate(): ?int
    {
        $total = $this->helpfulYes + $this->helpfulNo;

        return 0 === $total ? null : (int) round(100 * $this->helpfulYes / $total);
    }
}
