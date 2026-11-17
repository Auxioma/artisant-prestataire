<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Faq;

use App\Content\Application\Faq\FaqQuestion;
use App\Content\Application\Faq\FaqTopic;
use App\Content\Application\Port\FaqStore;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

final readonly class DbalFaqStore implements FaqStore
{
    private const string QUESTIONS = 'SELECT q.*, t.slug AS topic_slug, t.title AS topic_title
        FROM content.faq_question q JOIN content.faq_topic t ON t.id = q.topic_id
        WHERE q.published AND t.active';

    public function __construct(
        private Connection $connection,
        #[Target('app.content_sanitizer')]
        private HtmlSanitizerInterface $sanitizer,
    ) {
    }

    public function topics(): array
    {
        return array_map(self::topicRow(...), $this->connection->fetchAllAssociative(
            'SELECT t.*, count(q.id) AS question_count FROM content.faq_topic t JOIN content.faq_question q ON q.topic_id = t.id AND q.published
             WHERE t.active GROUP BY t.id ORDER BY t.position, t.title',
        ));
    }

    public function topic(string $slug): ?FaqTopic
    {
        $row = $this->connection->fetchAssociative(
            'SELECT t.*, count(q.id) AS question_count FROM content.faq_topic t JOIN content.faq_question q ON q.topic_id = t.id AND q.published
             WHERE t.active AND t.slug = :slug GROUP BY t.id',
            ['slug' => $slug],
        );

        return false === $row ? null : self::topicRow($row);
    }

    public function questionsOf(string $topicSlug): array
    {
        return $this->questions(self::QUESTIONS.' AND t.slug = :slug ORDER BY q.position', ['slug' => $topicSlug]);
    }

    public function question(string $topicSlug, string $questionSlug): ?FaqQuestion
    {
        return $this->questions(self::QUESTIONS.' AND t.slug = :topic AND q.slug = :slug', ['topic' => $topicSlug, 'slug' => $questionSlug])[0] ?? null;
    }

    public function search(string $term, int $limit): array
    {
        $like = '%'.addcslashes($term, '%_\\').'%';

        return $this->questions(
            // Texte visible seulement : ni balises HTML ni variables de contenu ({free_credits}…).
            self::QUESTIONS." AND catalog.immutable_unaccent(lower(q.question || ' ' || regexp_replace(q.answer_html, '<[^>]+>|\\{[a-z_]+\\}', ' ', 'g'))) LIKE catalog.immutable_unaccent(lower(:like))
              ORDER BY (catalog.immutable_unaccent(lower(q.question)) LIKE catalog.immutable_unaccent(lower(:like))) DESC, t.position, q.position LIMIT :limit",
            ['like' => $like, 'limit' => $limit],
        );
    }

    public function forAudience(string $audience, int $limit): array
    {
        return $this->questions(self::QUESTIONS.' AND q.audience = :audience ORDER BY t.position, q.position LIMIT :limit', ['audience' => $audience, 'limit' => $limit]);
    }

    public function popular(int $limit): array
    {
        return $this->questions(
            'SELECT * FROM ('.self::QUESTIONS.') x ORDER BY (SELECT position FROM content.faq_topic WHERE id = x.topic_id), x.position LIMIT :limit',
            ['limit' => $limit],
        );
    }

    public function vote(string $questionId, string $visitorHash, bool $helpful, \DateTimeImmutable $at): bool
    {
        $inserted = 1 === (int) $this->connection->executeStatement(
            'INSERT INTO content.faq_vote (question_id, visitor_hash, helpful, voted_at) VALUES (:q, :v, :h, :at) ON CONFLICT DO NOTHING',
            ['q' => $questionId, 'v' => $visitorHash, 'h' => $helpful ? 'true' : 'false', 'at' => $at->format(\DATE_ATOM)],
        );
        if ($inserted) {
            $this->connection->executeStatement(
                $helpful ? 'UPDATE content.faq_question SET helpful_yes = helpful_yes + 1 WHERE id = :q' : 'UPDATE content.faq_question SET helpful_no = helpful_no + 1 WHERE id = :q',
                ['q' => $questionId],
            );
        }

        return $inserted;
    }

    public function upsertTopic(string $id, string $slug, string $title, string $description, string $icon, int $position): string
    {
        return DbalValue::string($this->connection->fetchOne(
            'INSERT INTO content.faq_topic (id, slug, title, description, icon, position) VALUES (:id, :slug, :title, :description, :icon, :position)
             ON CONFLICT (slug) DO UPDATE SET title = EXCLUDED.title, description = EXCLUDED.description, icon = EXCLUDED.icon, position = EXCLUDED.position
             RETURNING id',
            ['id' => $id, 'slug' => $slug, 'title' => $title, 'description' => $description, 'icon' => $icon, 'position' => $position],
        ));
    }

    public function upsertQuestion(string $id, string $topicId, string $slug, string $question, string $answerHtml, string $audience, int $position, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(
            'INSERT INTO content.faq_question (id, topic_id, slug, question, answer_html, audience, position, updated_at)
             VALUES (:id, :topic, :slug, :question, :answer, :audience, :position, :at)
             ON CONFLICT (topic_id, slug) DO UPDATE SET question = EXCLUDED.question, answer_html = EXCLUDED.answer_html,
                 audience = EXCLUDED.audience, position = EXCLUDED.position,
                 updated_at = CASE WHEN content.faq_question.answer_html IS DISTINCT FROM EXCLUDED.answer_html
                                     OR content.faq_question.question IS DISTINCT FROM EXCLUDED.question
                                   THEN EXCLUDED.updated_at ELSE content.faq_question.updated_at END',
            ['id' => $id, 'topic' => $topicId, 'slug' => $slug, 'question' => $question, 'answer' => $this->sanitizer->sanitize($answerHtml),
                'audience' => $audience, 'position' => $position, 'at' => $at->format(\DATE_ATOM)],
        );
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<FaqQuestion>
     */
    private function questions(string $sql, array $params): array
    {
        return array_map(static fn (array $r): FaqQuestion => new FaqQuestion(
            DbalValue::string($r['id']),
            DbalValue::string($r['topic_slug']),
            DbalValue::string($r['topic_title']),
            DbalValue::string($r['slug']),
            DbalValue::string($r['question']),
            DbalValue::string($r['answer_html']),
            DbalValue::string($r['audience']),
            DbalValue::int($r['helpful_yes']),
            DbalValue::int($r['helpful_no']),
            new \DateTimeImmutable(DbalValue::string($r['updated_at'])),
        ), $this->connection->fetchAllAssociative($sql, $params));
    }

    /** @param array<string, mixed> $r */
    private static function topicRow(array $r): FaqTopic
    {
        return new FaqTopic(DbalValue::string($r['id']), DbalValue::string($r['slug']), DbalValue::string($r['title']), DbalValue::string($r['description']), DbalValue::string($r['icon']), DbalValue::int($r['question_count']));
    }
}
