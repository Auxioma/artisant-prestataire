<?php

declare(strict_types=1);

namespace App\Tests\Unit\Administration\Infrastructure;

use App\Administration\Infrastructure\Scheduler\AuditIntegrityTask;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Domain\Audit\AuditRecord;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

/** T-906a : la vérification nocturne du journal d'audit lève une alerte CRITICAL seulement en cas d'altération. */
final class AuditIntegrityTaskTest extends TestCase
{
    public function testAlertsOnlyWhenTheChainIsBroken(): void
    {
        $records = new TestHandler();
        $logger = new Logger('test', [$records]);

        (new AuditIntegrityTask($this->trail(null), $logger))();
        self::assertSame([], $records->getRecords());

        (new AuditIntegrityTask($this->trail('0190f5e4-0000-7000-8000-0000000000aa'), $logger))();
        self::assertCount(1, $records->getRecords());
        self::assertTrue($records->hasCriticalThatContains('Journal d’audit altéré'));
    }

    private function trail(?string $broken): AuditTrail
    {
        return new readonly class($broken) implements AuditTrail {
            public function __construct(private ?string $broken)
            {
            }

            public function record(AuditRecord $record): void
            {
            }

            public function verifyChain(): ?string
            {
                return $this->broken;
            }
        };
    }
}
