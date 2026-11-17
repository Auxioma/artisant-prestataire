<?php

declare(strict_types=1);

namespace App\Administration\Application\Command;

use App\Administration\Application\Port\SettingStore;
use App\Administration\Domain\Event\SettingChanged;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Application\Settings\InvalidSettingValue;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

final readonly class ChangeSettingHandler implements CommandHandler
{
    private const int REASON_MIN_LENGTH = 5;

    public function __construct(
        private SettingStore $store,
        private AuditTrail $auditTrail,
        private EventBus $eventBus,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(ChangeSetting $command): void
    {
        $key = SettingKey::tryFrom($command->key) ?? throw new \InvalidArgumentException(\sprintf('Paramètre inconnu « %s ».', $command->key));
        if (mb_strlen(trim($command->reason)) < self::REASON_MIN_LENGTH) {
            throw InvalidSettingValue::for($key, 'motif obligatoire');
        }
        $definition = $key->definition();
        $value = $definition->normalize($key, $command->value);
        $before = $this->store->find($key) ?? $definition->default;
        $now = $this->clock->now();

        if (!$this->store->save($key, $value, $command->adminId, trim($command->reason), $now)) {
            return;
        }

        $this->auditTrail->record(new AuditRecord(
            id: $this->ids->next(),
            occurredAt: $now,
            actorType: 'admin',
            actorId: $command->adminId,
            action: 'administration.setting_changed',
            subjectType: 'setting',
            subjectId: $key->value,
            ip: $command->ip,
            context: [
                'before' => json_encode($before, \JSON_THROW_ON_ERROR),
                'after' => json_encode($value, \JSON_THROW_ON_ERROR),
                'reason' => trim($command->reason),
            ],
        ));
        $this->eventBus->publish(new SettingChanged($key->value, $command->adminId, $now));
    }
}
