<?php

declare(strict_types=1);

namespace App\Tests\Unit\Administration\Application;

use App\Administration\Application\Command\ChangeSetting;
use App\Administration\Application\Command\ChangeSettingHandler;
use App\Administration\Domain\Event\SettingChanged;
use App\Shared\Application\Settings\InvalidSettingValue;
use App\Shared\Infrastructure\Identifier\UuidV7Generator;
use App\Tests\Support\Fake\FixedClock;
use App\Tests\Support\Fake\InMemoryAuditTrail;
use App\Tests\Support\Fake\InMemorySettingStore;
use App\Tests\Support\Fake\RecordingEventBus;
use PHPUnit\Framework\TestCase;

/** BO13 : toute modification de paramètre est validée, motivée, historisée et journalisée (avant/après). */
final class ChangeSettingHandlerTest extends TestCase
{
    private const string ADMIN = '0190f5d2-6c1e-7a3b-9c4d-000000000001';

    private InMemorySettingStore $store;
    private InMemoryAuditTrail $audit;
    private RecordingEventBus $events;
    private ChangeSettingHandler $handler;

    protected function setUp(): void
    {
        $this->store = new InMemorySettingStore();
        $this->audit = new InMemoryAuditTrail();
        $this->events = new RecordingEventBus();
        $this->handler = new ChangeSettingHandler($this->store, $this->audit, $this->events, new UuidV7Generator(), new FixedClock());
    }

    public function testChangesValueWithHistoryAuditAndEvent(): void
    {
        ($this->handler)(new ChangeSetting('quotes.max_per_request', '4', self::ADMIN, 'Test pilote Normandie', '203.0.113.7'));

        self::assertSame(4, $this->store->values['quotes.max_per_request']);
        self::assertCount(1, $this->store->history);
        self::assertCount(1, $this->audit->records);
        $record = $this->audit->records[0];
        self::assertSame('administration.setting_changed', $record->action);
        self::assertSame('3', $record->context['before']);
        self::assertSame('4', $record->context['after']);
        self::assertSame('203.0.113.7', $record->ip);
        self::assertCount(1, $this->events->published);
        self::assertInstanceOf(SettingChanged::class, $this->events->published[0]);
    }

    public function testNoOpWhenValueUnchanged(): void
    {
        ($this->handler)(new ChangeSetting('quotes.max_per_request', 4, self::ADMIN, 'Premier changement'));
        ($this->handler)(new ChangeSetting('quotes.max_per_request', '4', self::ADMIN, 'Même valeur'));

        self::assertCount(1, $this->store->history);
        self::assertCount(1, $this->audit->records);
        self::assertCount(1, $this->events->published);
    }

    public function testRequiresReason(): void
    {
        $this->expectException(InvalidSettingValue::class);
        ($this->handler)(new ChangeSetting('quotes.max_per_request', 4, self::ADMIN, '  '));
    }

    public function testRejectsOutOfBoundsValueWithoutSideEffect(): void
    {
        try {
            ($this->handler)(new ChangeSetting('auth.password_min_length', 8, self::ADMIN, 'Tentative de baisse'));
            self::fail('Exception attendue');
        } catch (InvalidSettingValue) {
        }

        self::assertSame([], $this->store->history);
        self::assertSame([], $this->audit->records);
        self::assertSame([], $this->events->published);
    }

    public function testRejectsUnknownKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ($this->handler)(new ChangeSetting('quotes.unknown', 1, self::ADMIN, 'Clé inconnue'));
    }
}
