<?php

declare(strict_types=1);

namespace App\Messaging\Application\Conversation;

use App\Shared\Application\Bus\Command;

/** PR07.1 — ajoute une réponse type de l'artisan. */
final readonly class SaveQuickReply implements Command
{
    public function __construct(public string $userId, public string $id, public string $title, public string $body)
    {
    }
}
