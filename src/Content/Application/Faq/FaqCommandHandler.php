<?php

declare(strict_types=1);

namespace App\Content\Application\Faq;

use App\Content\Application\Port\FaqStore;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

final readonly class FaqCommandHandler implements CommandHandler
{
    private const array AUDIENCES = ['all', 'client', 'artisan'];
    private const string SLUG = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    public function __construct(
        private FaqStore $store,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(VoteFaqAnswer|ImportFaq $command): void
    {
        $now = $this->clock->now();
        if ($command instanceof VoteFaqAnswer) {
            $this->store->vote($command->questionId, $command->visitorHash, $command->helpful, $now);

            return;
        }
        foreach ($command->topics as $t => $topic) {
            if (1 !== preg_match(self::SLUG, $topic['slug'])) {
                throw new \InvalidArgumentException(\sprintf('Rubrique « %s » : slug invalide.', $topic['slug']));
            }
            $topicId = $this->store->upsertTopic($this->ids->next(), $topic['slug'], $topic['title'], $topic['description'], $topic['icon'], $t);
            foreach ($topic['questions'] as $q => $question) {
                if (1 !== preg_match(self::SLUG, $question['slug']) || !\in_array($question['audience'], self::AUDIENCES, true)) {
                    throw new \InvalidArgumentException(\sprintf('Question « %s » : slug ou public invalide.', $question['slug']));
                }
                $this->store->upsertQuestion($this->ids->next(), $topicId, $question['slug'], $question['question'], $question['answer'], $question['audience'], $q, $now);
            }
        }
    }
}
