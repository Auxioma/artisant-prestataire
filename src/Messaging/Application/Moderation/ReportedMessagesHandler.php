<?php

declare(strict_types=1);

namespace App\Messaging\Application\Moderation;

use App\Messaging\Application\Conversation\MessageRiskScorer;
use App\Messaging\Application\Conversation\ReportMessage;
use App\Messaging\Application\Port\MessageModeration;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Application\Moderation\ReportedMessageItem;
use App\Shared\Application\Moderation\ReportedMessages;
use App\Shared\Application\Moderation\RevealMessageOriginal;
use App\Shared\Application\Security\SecretBox;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/** BO05 « Messages signalés » — file (avec le contexte du fil) et lecture journalisée de l'original scellé (ADR-0014). */
final readonly class ReportedMessagesHandler implements QueryHandler
{
    private const int LIMIT = 100;
    /** Messages du fil affichés pour juger (le signalé et ceux qui le précèdent). */
    private const int CONTEXT = 6;

    public function __construct(private MessageModeration $moderation, private SecretBox $secrets, private AuditTrail $audit, private IdGenerator $ids, private Clock $clock)
    {
    }

    /** @return list<ReportedMessageItem>|string|null */
    public function __invoke(ReportedMessages|RevealMessageOriginal $query): array|string|null
    {
        if ($query instanceof RevealMessageOriginal) {
            return $this->reveal($query);
        }

        return array_map(fn (array $r): ReportedMessageItem => new ReportedMessageItem(
            $r['id'],
            $r['conversation_id'],
            $r['sender_name'],
            $r['sender_role'],
            $r['reporter_name'],
            $r['body'],
            $r['masked'],
            $r['has_attachment'],
            $r['reason'],
            MessageRiskScorer::label($r['reason']) ?? ReportMessage::REASONS[$r['reason']] ?? $r['reason'],
            $r['sent_at'],
            $r['reported_at'],
            $r['request_number'],
            array_map(static fn (array $c): array => ['author' => $c['author'], 'body' => $c['body'], 'at' => $c['at'], 'reported' => $c['id'] === $r['id']], $this->moderation->contextOf($r['id'], self::CONTEXT)),
        ), $this->moderation->pending(self::LIMIT));
    }

    private function reveal(RevealMessageOriginal $query): ?string
    {
        $sealed = $this->moderation->sealedOriginal($query->messageId);
        if (null === $sealed) {
            return null;
        }
        $this->audit->record(new AuditRecord($this->ids->next(), $this->clock->now(), 'admin', $query->adminId, 'message.original_read', 'message', $query->messageId, $query->ip));

        return $this->secrets->open($sealed);
    }
}
