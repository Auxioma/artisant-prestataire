<?php

declare(strict_types=1);

namespace App\Messaging\Domain\Conversation;

use App\Messaging\Domain\Conversation\Event\MessagePosted;
use App\Shared\Domain\AggregateRoot;

/**
 * C04 / PR07 — fil entre un client et un artisan, au sujet d'une demande (RG-081 : l'artisan n'initie que sur une
 * demande qui lui est accessible) ou en question préalable (Q14). Seuls les deux participants écrivent ; un blocage
 * par l'un d'eux ferme le fil. Les messages sont ajoutés un à un (`post`), jamais modifiés.
 */
final class Conversation extends AggregateRoot
{
    private ?string $blockedBy = null;
    private ?\DateTimeImmutable $lastMessageAt = null;
    /** @var list<Message> messages postés pendant cette opération (persistés par le dépôt) */
    private array $posted = [];

    private function __construct(
        private readonly ConversationId $id,
        private readonly ConversationKind $kind,
        private readonly ?string $requestId,
        private readonly string $clientUserId,
        private readonly string $artisanId,
        private readonly string $artisanUserId,
        private readonly string $startedByUserId,
        private readonly \DateTimeImmutable $createdAt,
    ) {
    }

    public static function start(ConversationId $id, ConversationKind $kind, ?string $requestId, string $clientUserId, string $artisanId, string $artisanUserId, string $startedByUserId, \DateTimeImmutable $now): self
    {
        if ((ConversationKind::Request === $kind) !== (null !== $requestId)) {
            throw new MessagingRuleViolation('Une conversation de demande porte sur une demande, une question préalable non.');
        }
        if ($clientUserId === $artisanUserId || !\in_array($startedByUserId, [$clientUserId, $artisanUserId], true)) {
            throw new MessagingRuleViolation('Participants invalides.');
        }
        if (ConversationKind::PreQuestion === $kind && $startedByUserId !== $clientUserId) {
            throw new MessagingRuleViolation('Seul le client peut poser une question préalable (pas de démarchage, RG-081).');
        }

        return new self($id, $kind, $requestId, $clientUserId, $artisanId, $artisanUserId, $startedByUserId, $now);
    }

    /** Reconstitution depuis le stockage. */
    public static function restore(ConversationId $id, ConversationKind $kind, ?string $requestId, string $clientUserId, string $artisanId, string $artisanUserId, string $startedByUserId, ?string $blockedBy, ?\DateTimeImmutable $lastMessageAt, \DateTimeImmutable $createdAt): self
    {
        $conversation = new self($id, $kind, $requestId, $clientUserId, $artisanId, $artisanUserId, $startedByUserId, $createdAt);
        $conversation->blockedBy = $blockedBy;
        $conversation->lastMessageAt = $lastMessageAt;

        return $conversation;
    }

    public function post(Message $message): void
    {
        $this->guardParticipant($message->senderUserId);
        if (null !== $this->blockedBy) {
            throw new MessagingRuleViolation('Cette conversation est bloquée : vous ne pouvez plus y écrire.');
        }
        $this->posted[] = $message;
        $this->lastMessageAt = $message->at;
        $this->recordEvent(new MessagePosted($this->id->value, $message->id, $message->senderUserId, $this->counterpartOf($message->senderUserId), $message->at));
    }

    public function block(string $userId): void
    {
        $this->guardParticipant($userId);
        $this->blockedBy ??= $userId;
    }

    /** Seul celui qui a bloqué peut débloquer. */
    public function unblock(string $userId): void
    {
        if ($this->blockedBy !== $userId) {
            throw new MessagingRuleViolation('Seul l’auteur du blocage peut le lever.');
        }
        $this->blockedBy = null;
    }

    public function isParticipant(string $userId): bool
    {
        return \in_array($userId, [$this->clientUserId, $this->artisanUserId], true);
    }

    public function counterpartOf(string $userId): string
    {
        $this->guardParticipant($userId);

        return $userId === $this->clientUserId ? $this->artisanUserId : $this->clientUserId;
    }

    /** @return list<Message> */
    public function releasePosted(): array
    {
        [$posted, $this->posted] = [$this->posted, []];

        return $posted;
    }

    public function id(): ConversationId
    {
        return $this->id;
    }

    public function kind(): ConversationKind
    {
        return $this->kind;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    public function clientUserId(): string
    {
        return $this->clientUserId;
    }

    public function artisanId(): string
    {
        return $this->artisanId;
    }

    public function artisanUserId(): string
    {
        return $this->artisanUserId;
    }

    public function startedByUserId(): string
    {
        return $this->startedByUserId;
    }

    public function blockedBy(): ?string
    {
        return $this->blockedBy;
    }

    public function lastMessageAt(): ?\DateTimeImmutable
    {
        return $this->lastMessageAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    private function guardParticipant(string $userId): void
    {
        if (!$this->isParticipant($userId)) {
            throw new MessagingRuleViolation('Vous ne participez pas à cette conversation.');
        }
    }
}
