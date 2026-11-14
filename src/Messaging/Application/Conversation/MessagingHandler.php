<?php

declare(strict_types=1);

namespace App\Messaging\Application\Conversation;

use App\Messaging\Application\Port\MessagingDirectory;
use App\Messaging\Application\Port\ParticipantStates;
use App\Messaging\Domain\Conversation\Conversation;
use App\Messaging\Domain\Conversation\ConversationId;
use App\Messaging\Domain\Conversation\ConversationKind;
use App\Messaging\Domain\Conversation\ConversationRepository;
use App\Messaging\Domain\Conversation\Message;
use App\Messaging\Domain\Conversation\MessagingRuleViolation;
use App\QuoteRequest\Application\Pro\ProRequestAccess;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Application\Security\SecretBox;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Text\ContactDetector;
use App\Shared\Domain\Time\Clock;

/**
 * C04 / PR07 — envoi des messages. RG-081 : l'artisan n'écrit le premier qu'au sujet d'une demande qui lui est
 * accessible ; le client écrit à un artisan qui lui a envoyé un devis, ou lui pose une question préalable (Q14,
 * `messages.pre_question_enabled`, plafond quotidien). RG-080 / ADR-0014 : tant qu'aucun devis de l'artisan n'est
 * accepté sur la demande (`messages.mask_contacts_before_accept`), les coordonnées sont masquées et l'original scellé.
 */
final readonly class MessagingHandler implements CommandHandler
{
    private const string DAY = '-1 day';

    public function __construct(
        private ConversationRepository $conversations,
        private MessagingDirectory $directory,
        private ParticipantStates $participants,
        private ProRequestAccess $requestAccess,
        private SecretBox $secrets,
        private SettingsProvider $settings,
        private EventBus $events,
        private Clock $clock,
    ) {
    }

    public function __invoke(StartConversation|SendMessage|ConversationAction|ReportMessage $command): void
    {
        $now = $this->clock->now();
        match (true) {
            $command instanceof StartConversation => $this->start($command, $now),
            $command instanceof SendMessage => $this->send($command, $now),
            $command instanceof ConversationAction => $this->act($command, $now),
            default => $this->report($command, $now),
        };
    }

    private function start(StartConversation $command, \DateTimeImmutable $now): void
    {
        $conversation = $this->locate($command, $now);
        $this->postTo($conversation, $command->userId, $command->messageId, $command->body, $now);
    }

    private function locate(StartConversation $command, \DateTimeImmutable $now): Conversation
    {
        $me = $this->directory->artisanOfUser($command->userId);
        if (null !== $me) {
            // RG-081 : pas de démarchage, l'artisan ne parle que d'une demande qu'il a reçue.
            $request = null === $command->requestId ? null : $this->directory->request($command->requestId);
            if (null === $request || null === $command->requestId || !$this->requestAccess->canView($command->userId, $command->requestId)) {
                throw new MessagingRuleViolation('Vous ne pouvez écrire qu’au sujet d’une demande que vous avez reçue.');
            }
            $existing = $this->conversations->findForRequest($command->requestId, $me['artisan_id']);

            return $existing ?? Conversation::start(new ConversationId($command->conversationId), ConversationKind::Request, $command->requestId, $request['client_user_id'], $me['artisan_id'], $me['user_id'], $command->userId, $now);
        }
        $artisan = null === $command->artisanId ? null : $this->directory->artisan($command->artisanId);
        if (null === $artisan) {
            throw new MessagingRuleViolation('Professionnel introuvable.');
        }
        if (null !== $command->requestId) {
            $request = $this->directory->request($command->requestId);
            if (null === $request || $request['client_user_id'] !== $command->userId || !$this->directory->hasSentQuote($command->requestId, $artisan['artisan_id'])) {
                throw new MessagingRuleViolation('Vous pouvez écrire aux professionnels qui vous ont envoyé un devis sur cette demande.');
            }

            return $this->conversations->findForRequest($command->requestId, $artisan['artisan_id'])
                ?? Conversation::start(new ConversationId($command->conversationId), ConversationKind::Request, $command->requestId, $command->userId, $artisan['artisan_id'], $artisan['user_id'], $command->userId, $now);
        }
        $existing = $this->conversations->findPreQuestion($command->userId, $artisan['artisan_id']);
        if (null !== $existing) {
            return $existing;
        }
        if (!$this->settings->bool(SettingKey::MessagesPreQuestionEnabled) || !$artisan['public']) {
            throw new MessagingRuleViolation('Pour échanger avec ce professionnel, déposez une demande de devis.');
        }
        if ($this->directory->preQuestionsSince($command->userId, $now->modify(self::DAY)) >= $this->settings->int(SettingKey::MessagesPreQuestionDailyMax)) {
            throw new MessagingRuleViolation('Vous avez atteint le nombre de questions préalables pour aujourd’hui : déposez une demande de devis.');
        }

        return Conversation::start(new ConversationId($command->conversationId), ConversationKind::PreQuestion, null, $command->userId, $artisan['artisan_id'], $artisan['user_id'], $command->userId, $now);
    }

    private function send(SendMessage $command, \DateTimeImmutable $now): void
    {
        $conversation = $this->conversations->findForUpdate(new ConversationId($command->conversationId));
        if (null === $conversation || !$conversation->isParticipant($command->userId)) {
            throw new MessagingRuleViolation('Conversation introuvable.');
        }
        $this->postTo($conversation, $command->userId, $command->messageId, $command->body, $now);
    }

    private function postTo(Conversation $conversation, string $userId, string $messageId, string $body, \DateTimeImmutable $now): void
    {
        $body = trim(str_replace("\r\n", "\n", $body));
        $sealed = null;
        if ($this->masking($conversation)) {
            [$masked, $found] = ContactDetector::mask($body);
            if ([] !== $found) {
                $sealed = $this->secrets->seal($body);
                $body = $masked;
            }
        }
        $conversation->post(new Message($messageId, $userId, $body, $sealed, $now));
        $this->conversations->save($conversation);
        foreach ([$conversation->clientUserId(), $conversation->artisanUserId()] as $participant) {
            $this->participants->ensure($conversation->id()->value, $participant);
        }
        $this->participants->markRead($conversation->id()->value, $userId, $now);
        $this->participants->archive($conversation->id()->value, $conversation->counterpartOf($userId), false);
        $this->events->publish(...$conversation->releaseEvents());
    }

    private function masking(Conversation $conversation): bool
    {
        if (!$this->settings->bool(SettingKey::MessagesMaskContactsBeforeAccept)) {
            return false;
        }
        $requestId = $conversation->requestId();

        return null === $requestId || !$this->directory->quoteAccepted($requestId, $conversation->artisanId());
    }

    private function act(ConversationAction $command, \DateTimeImmutable $now): void
    {
        $conversation = $this->conversations->findForUpdate(new ConversationId($command->conversationId));
        if (null === $conversation || !$conversation->isParticipant($command->userId)) {
            throw new MessagingRuleViolation('Conversation introuvable.');
        }
        $id = $conversation->id()->value;
        match ($command->action) {
            ConversationAction::READ => $this->participants->markRead($id, $command->userId, $now),
            ConversationAction::ARCHIVE, ConversationAction::UNARCHIVE => $this->participants->archive($id, $command->userId, ConversationAction::ARCHIVE === $command->action),
            ConversationAction::BLOCK => $conversation->block($command->userId),
            ConversationAction::UNBLOCK => $conversation->unblock($command->userId),
            default => throw new MessagingRuleViolation('Action inconnue.'),
        };
        if (\in_array($command->action, [ConversationAction::BLOCK, ConversationAction::UNBLOCK], true)) {
            $this->conversations->save($conversation);
        }
    }

    private function report(ReportMessage $command, \DateTimeImmutable $now): void
    {
        $conversation = $this->conversations->find(new ConversationId($command->conversationId));
        if (null === $conversation || !$conversation->isParticipant($command->userId) || !\array_key_exists($command->reason, ReportMessage::REASONS)) {
            throw new MessagingRuleViolation('Signalement impossible.');
        }
        if (!$this->participants->report($command->messageId, $command->userId, $command->reason, $now)) {
            throw new MessagingRuleViolation('Ce message est déjà signalé, ou vous en êtes l’auteur.');
        }
    }
}
