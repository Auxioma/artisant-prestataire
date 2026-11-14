<?php

declare(strict_types=1);

namespace App\Messaging\Application\Query;

use App\Shared\Application\Bus\QueryHandler;

final readonly class ConversationQueriesHandler implements QueryHandler
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(private ConversationReader $reader)
    {
    }

    public function __invoke(Conversations|OpenConversation|UnreadMessages $query): ConversationsPage|ConversationThread|int|null
    {
        if ($query instanceof UnreadMessages) {
            return $this->reader->unreadCount($query->userId);
        }
        if ($query instanceof OpenConversation) {
            return 1 === preg_match(self::UUID, $query->conversationId) ? $this->reader->thread($query->userId, $query->conversationId) : null;
        }
        $filter = \in_array($query->filter, Conversations::FILTERS, true) ? $query->filter : 'all';
        $page = max(1, $query->page);
        $result = $this->reader->list($query->userId, $filter, trim($query->query), $page, Conversations::PER_PAGE);

        return new ConversationsPage($result['rows'], $result['total'], $page, Conversations::PER_PAGE, $this->reader->unreadCount($query->userId));
    }
}
