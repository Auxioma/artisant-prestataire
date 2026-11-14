<?php

declare(strict_types=1);

namespace App\Messaging\Application\Conversation;

use App\Shared\Application\Bus\Command;

final readonly class SendMessage implements Command
{
    public function __construct(public string $userId, public string $conversationId, public string $messageId, public string $body)
    {
    }
}
