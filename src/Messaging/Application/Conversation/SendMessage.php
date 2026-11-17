<?php

declare(strict_types=1);

namespace App\Messaging\Application\Conversation;

use App\Shared\Application\Bus\Command;

/** Message dans un fil existant, avec au plus une pièce jointe (fichier temporaire reçu, nom d'origine). */
final readonly class SendMessage implements Command
{
    public function __construct(public string $userId, public string $conversationId, public string $messageId, public string $body, public ?string $attachmentPath = null, public ?string $attachmentName = null)
    {
    }
}
