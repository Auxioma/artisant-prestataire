<?php

declare(strict_types=1);

namespace App\Messaging\Application\Moderation;

use App\Messaging\Application\Port\MessageModeration;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Moderation\DecideReportedMessage;
use App\Shared\Application\Moderation\ModerationDecisionRejected;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/** BO05 — signalement classé, ou message retiré de l'affichage (motif obligatoire) ; décision journalisée. */
final readonly class DecideReportedMessageHandler implements CommandHandler
{
    public function __construct(private MessageModeration $moderation, private AuditTrail $audit, private IdGenerator $ids, private Clock $clock)
    {
    }

    public function __invoke(DecideReportedMessage $command): void
    {
        $reason = $command->remove ? $command->reason : null;
        if ($command->remove && (null === $reason || !\array_key_exists($reason, DecideReportedMessage::REASONS))) {
            throw new ModerationDecisionRejected('Choisissez le motif du retrait.');
        }
        $decision = $command->remove ? 'removed' : 'dismissed';
        $now = $this->clock->now();
        if (!$this->moderation->decide($command->messageId, $command->adminId, $decision, $reason, $now)) {
            throw new ModerationDecisionRejected('Ce signalement a déjà été traité.');
        }
        $this->audit->record(new AuditRecord($this->ids->next(), $now, 'admin', $command->adminId, 'message.moderated', 'message', $command->messageId, context: ['decision' => $decision, 'reason' => $reason]));
    }
}
