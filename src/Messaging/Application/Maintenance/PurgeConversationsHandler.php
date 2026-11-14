<?php

declare(strict_types=1);

namespace App\Messaging\Application\Maintenance;

use App\Messaging\Domain\Conversation\ConversationRepository;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

final readonly class PurgeConversationsHandler implements CommandHandler
{
    public function __construct(private ConversationRepository $conversations, private SettingsProvider $settings, private AuditTrail $audit, private IdGenerator $ids, private Clock $clock)
    {
    }

    public function __invoke(PurgeConversations $command): void
    {
        $now = $this->clock->now();
        $years = $this->settings->int(SettingKey::MessagesRetentionYears);
        $purged = $this->conversations->purgeInactiveBefore($now->modify(\sprintf('-%d years', $years)));
        if ($purged > 0) {
            $this->audit->record(new AuditRecord($this->ids->next(), $now, 'system', null, 'messaging.purged', 'conversation', null, context: ['count' => $purged, 'years' => $years]));
        }
    }
}
