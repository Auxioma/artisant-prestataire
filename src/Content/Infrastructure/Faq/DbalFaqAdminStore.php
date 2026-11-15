<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Faq;

use App\Content\Application\Faq\Admin\FaqAdminQuestion;
use App\Content\Application\Faq\Admin\FaqAdminStore;
use App\Content\Application\Faq\Admin\FaqAdminTopic;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

/** BO10.4 — écriture de l'aide ; les réponses arrivent déjà assainies (RichText). */
final readonly class DbalFaqAdminStore implements FaqAdminStore
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const string TOPICS = <<<'SQL'
        SELECT t.*, count(q.id) AS questions, coalesce(sum(q.helpful_yes), 0) AS yes, coalesce(sum(q.helpful_no), 0) AS no
        FROM content.faq_topic t LEFT JOIN content.faq_question q ON q.topic_id = t.id
        SQL;

    public function __construct(private Connection $connection)
    {
    }

    public function topics(): array
    {
        return array_map(self::topicView(...), $this->connection->fetchAllAssociative(self::TOPICS.' GROUP BY t.id ORDER BY t.position, t.title'));
    }

    public function topic(string $id): ?FaqAdminTopic
    {
        if (1 !== preg_match(self::UUID, $id)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(self::TOPICS.' WHERE t.id = :id GROUP BY t.id', ['id' => $id]);

        return false === $row ? null : self::topicView($row);
    }

    public function questions(string $topicId): array
    {
        if (1 !== preg_match(self::UUID, $topicId)) {
            return [];
        }

        return array_map(self::questionView(...), $this->connection->fetchAllAssociative('SELECT * FROM content.faq_question WHERE topic_id = :t ORDER BY position, question', ['t' => $topicId]));
    }

    public function question(string $id): ?FaqAdminQuestion
    {
        if (1 !== preg_match(self::UUID, $id)) {
            return null;
        }
        $row = $this->connection->fetchAssociative('SELECT * FROM content.faq_question WHERE id = :id', ['id' => $id]);

        return false === $row ? null : self::questionView($row);
    }

    public function topicSlugTaken(string $slug, ?string $exceptId): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM content.faq_topic WHERE slug = :s AND id IS DISTINCT FROM :id', ['s' => $slug, 'id' => $exceptId]);
    }

    public function questionSlugTaken(string $topicId, string $slug, ?string $exceptId): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM content.faq_question WHERE topic_id = :t AND slug = :s AND id IS DISTINCT FROM :id', ['t' => $topicId, 's' => $slug, 'id' => $exceptId]);
    }

    public function saveTopic(string $id, string $slug, string $title, string $description, string $icon, bool $active): void
    {
        $this->connection->executeStatement(
            'INSERT INTO content.faq_topic (id, slug, title, description, icon, position, active)
             VALUES (:id, :slug, :title, :description, :icon, (SELECT coalesce(max(position) + 1, 0) FROM content.faq_topic), :active)
             ON CONFLICT (id) DO UPDATE SET slug = EXCLUDED.slug, title = EXCLUDED.title, description = EXCLUDED.description, icon = EXCLUDED.icon, active = EXCLUDED.active',
            ['id' => $id, 'slug' => $slug, 'title' => $title, 'description' => $description, 'icon' => $icon, 'active' => $active ? 'true' : 'false'],
        );
    }

    public function saveQuestion(string $id, string $topicId, string $slug, string $question, string $answerHtml, string $audience, bool $published, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(
            'INSERT INTO content.faq_question (id, topic_id, slug, question, answer_html, audience, position, published, updated_at)
             VALUES (:id, :topic, :slug, :question, :answer, :audience, (SELECT coalesce(max(position) + 1, 0) FROM content.faq_question WHERE topic_id = :topic), :published, :at)
             ON CONFLICT (id) DO UPDATE SET topic_id = EXCLUDED.topic_id, slug = EXCLUDED.slug, question = EXCLUDED.question, answer_html = EXCLUDED.answer_html,
                 audience = EXCLUDED.audience, published = EXCLUDED.published, updated_at = EXCLUDED.updated_at,
                 position = CASE WHEN content.faq_question.topic_id = EXCLUDED.topic_id THEN content.faq_question.position ELSE EXCLUDED.position END',
            ['id' => $id, 'topic' => $topicId, 'slug' => $slug, 'question' => $question, 'answer' => $answerHtml, 'audience' => $audience, 'published' => $published ? 'true' : 'false', 'at' => $at->format(\DATE_ATOM)],
        );
    }

    public function moveTopic(string $id, int $direction): void
    {
        $this->swap('content.faq_topic', $id, $direction, null);
    }

    public function moveQuestion(string $id, int $direction): void
    {
        $topic = $this->connection->fetchOne('SELECT topic_id FROM content.faq_question WHERE id = :id', ['id' => $id]);
        if (\is_string($topic)) {
            $this->swap('content.faq_question', $id, $direction, $topic);
        }
    }

    public function deleteTopic(string $id): void
    {
        $this->connection->delete('content.faq_topic', ['id' => $id]);
    }

    public function deleteQuestion(string $id): void
    {
        $this->connection->delete('content.faq_question', ['id' => $id]);
    }

    /** Renumérote 0..n puis échange avec le voisin (positions toujours denses). */
    private function swap(string $table, string $id, int $direction, ?string $topicId): void
    {
        $scope = null === $topicId ? '' : ' WHERE topic_id = :t';
        $ids = array_map(DbalValue::string(...), $this->connection->fetchFirstColumn('SELECT id FROM '.$table.$scope.' ORDER BY position, id', null === $topicId ? [] : ['t' => $topicId]));
        $index = array_search($id, $ids, true);
        if (false === $index || !isset($ids[$index + $direction])) {
            return;
        }
        [$ids[$index], $ids[$index + $direction]] = [$ids[$index + $direction], $ids[$index]];
        foreach ($ids as $position => $rowId) {
            $this->connection->update($table, ['position' => $position], ['id' => $rowId]);
        }
    }

    /** @param array<string, mixed> $r */
    private static function topicView(array $r): FaqAdminTopic
    {
        return new FaqAdminTopic(DbalValue::string($r['id']), DbalValue::string($r['slug']), DbalValue::string($r['title']), DbalValue::string($r['description']), DbalValue::string($r['icon']), DbalValue::int($r['position']), true === $r['active'], DbalValue::int($r['questions']), DbalValue::int($r['yes']), DbalValue::int($r['no']));
    }

    /** @param array<string, mixed> $r */
    private static function questionView(array $r): FaqAdminQuestion
    {
        return new FaqAdminQuestion(DbalValue::string($r['id']), DbalValue::string($r['topic_id']), DbalValue::string($r['slug']), DbalValue::string($r['question']), DbalValue::string($r['answer_html']), DbalValue::string($r['audience']), DbalValue::int($r['position']), true === $r['published'], DbalValue::int($r['helpful_yes']), DbalValue::int($r['helpful_no']), new \DateTimeImmutable(DbalValue::string($r['updated_at'])));
    }
}
