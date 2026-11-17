<?php

declare(strict_types=1);

namespace App\Content\Application\Port;

use App\Content\Application\Faq\FaqQuestion;
use App\Content\Application\Faq\FaqTopic;

interface FaqStore
{
    /** @return list<FaqTopic> rubriques actives ayant au moins une question publiée */
    public function topics(): array;

    public function topic(string $slug): ?FaqTopic;

    /** @return list<FaqQuestion> */
    public function questionsOf(string $topicSlug): array;

    public function question(string $topicSlug, string $questionSlug): ?FaqQuestion;

    /** @return list<FaqQuestion> */
    public function search(string $term, int $limit): array;

    /** @return list<FaqQuestion> */
    public function forAudience(string $audience, int $limit): array;

    /** @return list<FaqQuestion> */
    public function popular(int $limit): array;

    /** Enregistre le vote ; false si ce visiteur a déjà voté pour cette question. */
    public function vote(string $questionId, string $visitorHash, bool $helpful, \DateTimeImmutable $at): bool;

    public function upsertTopic(string $id, string $slug, string $title, string $description, string $icon, int $position): string;

    /** La réponse HTML est assainie avant stockage (liste blanche app.content_sanitizer). */
    public function upsertQuestion(string $id, string $topicId, string $slug, string $question, string $answerHtml, string $audience, int $position, \DateTimeImmutable $at): void;
}
