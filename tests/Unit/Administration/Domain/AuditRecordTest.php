<?php

declare(strict_types=1);

namespace App\Tests\Unit\Administration\Domain;

use App\Administration\Domain\Audit\AuditRecord;
use App\Shared\Domain\Exception\InvalidValue;
use PHPUnit\Framework\TestCase;

/** BO15 / cadrage §5.8 : journal d'audit inviolable par chaînage de hash. */
final class AuditRecordTest extends TestCase
{
    /**
     * @param array<string, scalar|null> $context
     */
    private function record(string $action = 'administration.setting_changed', array $context = ['b' => '2', 'a' => '1']): AuditRecord
    {
        return new AuditRecord(
            id: '0190f5d2-6c1e-7a3b-9c4d-1e2f3a4b5c6d',
            occurredAt: new \DateTimeImmutable('2026-11-11 10:00:00.123456', new \DateTimeZone('Europe/Paris')),
            actorType: 'admin',
            actorId: '0190f5d2-6c1e-7a3b-9c4d-000000000001',
            action: $action,
            subjectType: 'setting',
            subjectId: 'quotes.max_per_request',
            ip: '203.0.113.7',
            context: $context,
        );
    }

    public function testCanonicalFormIsDeterministicAndUtc(): void
    {
        $canonical = $this->record()->canonical();

        self::assertSame($canonical, $this->record(context: ['a' => '1', 'b' => '2'])->canonical());
        self::assertStringContainsString('"occurred_at":"2026-11-11T09:00:00.123456+00:00"', $canonical);
    }

    public function testSealChainsOnPreviousHash(): void
    {
        $record = $this->record();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $record->seal(null));
        self::assertNotSame($record->seal(null), $record->seal(str_repeat('a', 64)));
        self::assertNotSame($record->seal(null), $this->record(context: ['a' => 'altéré'])->seal(null));
    }

    public function testRejectsMalformedAction(): void
    {
        $this->expectException(InvalidValue::class);
        $this->record(action: 'Setting Changed');
    }
}
