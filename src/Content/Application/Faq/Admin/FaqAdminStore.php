<?php

declare(strict_types=1);

namespace App\Content\Application\Faq\Admin;

/** BO10.4 — écriture de l'aide (rubriques et questions, publiées ou non) et statistiques de votes. */
interface FaqAdminStore
{
    /** @return list<FaqAdminTopic> par position */
    public function topics(): array;

    public function topic(string $id): ?FaqAdminTopic;

    /** @return list<FaqAdminQuestion> par position */
    public function questions(string $topicId): array;

    public function question(string $id): ?FaqAdminQuestion;

    public function topicSlugTaken(string $slug, ?string $exceptId): bool;

    public function questionSlugTaken(string $topicId, string $slug, ?string $exceptId): bool;

    public function saveTopic(string $id, string $slug, string $title, string $description, string $icon, bool $active): void;

    public function saveQuestion(string $id, string $topicId, string $slug, string $question, string $answerHtml, string $audience, bool $published, \DateTimeImmutable $at): void;

    /** Échange la position avec l'élément voisin (`-1` : vers le haut). */
    public function moveTopic(string $id, int $direction): void;

    public function moveQuestion(string $id, int $direction): void;

    public function deleteTopic(string $id): void;

    public function deleteQuestion(string $id): void;
}
