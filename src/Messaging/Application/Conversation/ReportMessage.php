<?php

declare(strict_types=1);

namespace App\Messaging\Application\Conversation;

use App\Shared\Application\Bus\Command;

/** Signalement d'un message reçu (file de modération BO05, T-701d). */
final readonly class ReportMessage implements Command
{
    public const array REASONS = [
        'spam' => 'Démarchage ou publicité',
        'abuse' => 'Propos injurieux ou menaçants',
        'fraud' => 'Tentative de fraude',
        'contact' => 'Contournement de la plateforme',
        'other' => 'Autre',
    ];

    public function __construct(public string $userId, public string $conversationId, public string $messageId, public string $reason)
    {
    }
}
