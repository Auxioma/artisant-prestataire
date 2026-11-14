<?php

declare(strict_types=1);

namespace App\Messaging\Application\Conversation;

use App\Messaging\Application\Port\MessagingDirectory;
use App\Messaging\Application\Port\ParticipantStates;
use App\Messaging\Application\Port\QuickReplies;
use App\Messaging\Domain\Conversation\MessagingRuleViolation;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Domain\Time\Clock;

/** PR07 — outils de l'artisan : note privée par conversation, réponses types (PR07.1). */
final readonly class ArtisanToolsHandler implements CommandHandler
{
    public function __construct(
        private ParticipantStates $participants,
        private QuickReplies $quickReplies,
        private MessagingDirectory $directory,
        private Clock $clock,
    ) {
    }

    public function __invoke(SaveConversationNote|SaveQuickReply|DeleteQuickReply $command): void
    {
        match (true) {
            $command instanceof SaveConversationNote => $this->note($command),
            $command instanceof SaveQuickReply => $this->add($command),
            default => $this->remove($command),
        };
    }

    private function note(SaveConversationNote $command): void
    {
        $note = trim(str_replace("\r\n", "\n", $command->note));
        if (mb_strlen($note) > SaveConversationNote::MAX) {
            throw new MessagingRuleViolation(\sprintf('La note ne peut dépasser %d caractères.', SaveConversationNote::MAX));
        }
        if (!$this->participants->saveNote($command->conversationId, $command->userId, '' === $note ? null : $note)) {
            throw new MessagingRuleViolation('Conversation introuvable.');
        }
    }

    private function add(SaveQuickReply $command): void
    {
        $this->artisan($command->userId);
        $title = trim($command->title);
        $body = trim(str_replace("\r\n", "\n", $command->body));
        if ('' === $title || '' === $body) {
            throw new MessagingRuleViolation('Donnez un titre et un texte à votre réponse type.');
        }
        if (mb_strlen($title) > QuickReplies::TITLE_MAX || mb_strlen($body) > QuickReplies::BODY_MAX) {
            throw new MessagingRuleViolation(\sprintf('Titre limité à %d caractères, texte à %d.', QuickReplies::TITLE_MAX, QuickReplies::BODY_MAX));
        }
        if (\count($this->quickReplies->of($command->userId)) >= QuickReplies::MAX) {
            throw new MessagingRuleViolation(\sprintf('Vous avez déjà %d réponses types : supprimez-en une avant d’en ajouter.', QuickReplies::MAX));
        }
        $this->quickReplies->add($command->id, $command->userId, $title, $body, $this->clock->now());
    }

    private function remove(DeleteQuickReply $command): void
    {
        $this->artisan($command->userId);
        if (!$this->quickReplies->remove($command->id, $command->userId)) {
            throw new MessagingRuleViolation('Réponse type introuvable.');
        }
    }

    private function artisan(string $userId): void
    {
        if (null === $this->directory->artisanOfUser($userId)) {
            throw new MessagingRuleViolation('Les réponses types sont réservées aux professionnels.');
        }
    }
}
