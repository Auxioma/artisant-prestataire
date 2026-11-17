<?php

declare(strict_types=1);

namespace App\Messaging\Application\Conversation;

use App\Shared\Application\Bus\Command;

/** PR07 — note privée d'un participant sur une conversation (visible de lui seul) ; vide : effacée. */
final readonly class SaveConversationNote implements Command
{
    public const int MAX = 2000;

    public function __construct(public string $userId, public string $conversationId, public string $note)
    {
    }
}
