<?php

declare(strict_types=1);

namespace App\Messaging\Application\Query;

use App\Shared\Application\Bus\Query;

/** C04.2 — fil d'un participant ; réponse : ConversationThread ou null (inexistant ou non participant). */
final readonly class OpenConversation implements Query
{
    public function __construct(public string $userId, public string $conversationId)
    {
    }
}
