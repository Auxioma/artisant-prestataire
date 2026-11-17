<?php

declare(strict_types=1);

namespace App\Content\Application\Faq;

final readonly class FaqQuestion
{
    public function __construct(
        public string $id,
        public string $topicSlug,
        public string $topicTitle,
        public string $slug,
        public string $question,
        public string $answerHtml,
        public string $audience,
        public int $helpfulYes,
        public int $helpfulNo,
        public \DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * Variables de contenu ({max_devis}…) remplacées par les valeurs des paramètres (RG-014).
     *
     * @param array<string, string> $variables
     */
    public function withVariables(array $variables): self
    {
        return new self($this->id, $this->topicSlug, $this->topicTitle, $this->slug, strtr($this->question, $variables), strtr($this->answerHtml, $variables), $this->audience, $this->helpfulYes, $this->helpfulNo, $this->updatedAt);
    }
}
