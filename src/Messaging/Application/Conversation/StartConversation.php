<?php

declare(strict_types=1);

namespace App\Messaging\Application\Conversation;

use App\Shared\Application\Bus\Command;

/**
 * Premier message d'un fil : l'artisan au sujet d'une demande accessible (RG-081), le client à un artisan qui lui a
 * envoyé un devis, ou une question préalable (Q14, sans demande). Si le fil existe déjà, le message s'y ajoute.
 */
final readonly class StartConversation implements Command
{
    public function __construct(
        public string $userId,
        public string $conversationId,
        public string $messageId,
        public ?string $requestId,
        public ?string $artisanId,
        public string $body,
    ) {
    }
}
