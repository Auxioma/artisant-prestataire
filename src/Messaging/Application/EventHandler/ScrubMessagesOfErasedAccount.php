<?php

declare(strict_types=1);

namespace App\Messaging\Application\EventHandler;

use App\Messaging\Domain\Conversation\ConversationRepository;
use App\Shared\Application\Bus\EventHandler;
use App\Shared\Application\Storage\FileShredder;
use App\Shared\Domain\Event\AccountErased;

/** RG-090 — compte effacé : ses messages sont remplacés par une mention, ses pièces jointes et notes privées retirées. */
final readonly class ScrubMessagesOfErasedAccount implements EventHandler
{
    public function __construct(private ConversationRepository $conversations, private FileShredder $shredder)
    {
    }

    public function __invoke(AccountErased $event): void
    {
        foreach ($this->conversations->scrubMessagesOf($event->userId) as $file) {
            $this->shredder->shred($file, 'account_erased');
        }
    }
}
