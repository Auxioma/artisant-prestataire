<?php

declare(strict_types=1);

namespace App\Messaging\Domain\Conversation;

/** Conversation au sujet d'une demande (RG-081), ou question préalable d'un client à un artisan (Q14). */
enum ConversationKind: string
{
    case Request = 'request';
    case PreQuestion = 'pre_question';
}
