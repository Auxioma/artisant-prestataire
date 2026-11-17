<?php

declare(strict_types=1);

namespace App\Messaging\Application\Maintenance;

use App\Messaging\Domain\Conversation\ConversationRepository;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Application\Storage\FileShredder;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

final readonly class PurgeConversationsHandler implements CommandHandler
{
    private const string SHRED_REASON = 'messages_retention';

    public function __construct(private ConversationRepository $conversations, private SettingsProvider $settings, private AuditTrail $audit, private FileShredder $shredder, private IdGenerator $ids, private Clock $clock)
    {
    }

    public function __invoke(PurgeConversations $command): void
    {
        $now = $this->clock->now();
        $years = $this->settings->int(SettingKey::MessagesRetentionYears);
        $before = $now->modify(\sprintf('-%d years', $years));
        foreach ($this->conversations->attachmentsInactiveBefore($before) as $fileId) {
            $this->shredder->shred($fileId, self::SHRED_REASON);
        }
        $purged = $this->conversations->purgeInactiveBefore($before);
        if ($purged > 0) {
            $this->audit->record(new AuditRecord($this->ids->next(), $now, 'system', null, 'messaging.purged', 'conversation', null, context: ['count' => $purged, 'years' => $years]));
        }
    }
}
