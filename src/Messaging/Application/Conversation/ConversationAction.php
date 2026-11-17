<?php

declare(strict_types=1);

namespace App\Messaging\Application\Conversation;

use App\Shared\Application\Bus\Command;

/** Lecture, archivage, blocage d'un fil par l'un de ses participants. */
final readonly class ConversationAction implements Command
{
    public const string READ = 'read';
    public const string ARCHIVE = 'archive';
    public const string UNARCHIVE = 'unarchive';
    public const string BLOCK = 'block';
    public const string UNBLOCK = 'unblock';

    public function __construct(public string $userId, public string $conversationId, public string $action)
    {
    }
}
