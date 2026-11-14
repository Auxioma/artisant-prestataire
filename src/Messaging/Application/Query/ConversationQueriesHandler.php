<?php

declare(strict_types=1);

namespace App\Messaging\Application\Query;

use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Application\Messaging\ArtisanResponseTime;
use App\Shared\Domain\Time\Clock;

final readonly class ConversationQueriesHandler implements QueryHandler
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    /** Délai de réponse (PR07.1) : premiers messages des 6 derniers mois, affiché à partir de 3 conversations. */
    private const string RESPONSE_WINDOW = '-6 months';
    private const int RESPONSE_MIN_SAMPLES = 3;

    public function __construct(private ConversationReader $reader, private Clock $clock)
    {
    }

    public function __invoke(Conversations|OpenConversation|UnreadMessages|ArtisanResponseTime $query): ConversationsPage|ConversationThread|int|null
    {
        if ($query instanceof ArtisanResponseTime) {
            return $this->reader->averageResponseMinutes($query->artisanId, $this->clock->now()->modify(self::RESPONSE_WINDOW), self::RESPONSE_MIN_SAMPLES);
        }
        if ($query instanceof UnreadMessages) {
            return $this->reader->unreadCount($query->userId);
        }
        if ($query instanceof OpenConversation) {
            return 1 === preg_match(self::UUID, $query->conversationId) ? $this->reader->thread($query->userId, $query->conversationId) : null;
        }
        $filter = \in_array($query->filter, Conversations::FILTERS, true) ? $query->filter : 'all';
        $page = max(1, $query->page);
        $result = $this->reader->list($query->userId, $filter, trim($query->query), $page, Conversations::PER_PAGE);

        return new ConversationsPage($result['rows'], $result['total'], $page, Conversations::PER_PAGE, $this->reader->unreadCount($query->userId), $this->reader->counts($query->userId));
    }
}
