<?php

declare(strict_types=1);

namespace App\Content\Application\Faq\Admin;

final readonly class FaqAdminQuestion
{
    public const array AUDIENCES = ['all' => 'Tous', 'client' => 'Clients', 'artisan' => 'Professionnels'];

    public function __construct(
        public string $id,
        public string $topicId,
        public string $slug,
        public string $question,
        public string $answerHtml,
        public string $audience,
        public int $position,
        public bool $published,
        public int $helpfulYes,
        public int $helpfulNo,
        public \DateTimeImmutable $updatedAt,
    ) {
    }

    public function helpfulRate(): ?int
    {
        $total = $this->helpfulYes + $this->helpfulNo;

        return 0 === $total ? null : (int) round(100 * $this->helpfulYes / $total);
    }
}
