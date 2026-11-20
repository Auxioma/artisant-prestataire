<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Security\ImpersonationContext;
use App\Shared\Application\Security\ImpersonationSession;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Infrastructure\Security\ImpersonationAwareAuditTrail;
use PHPUnit\Framework\TestCase;

/** T-807 — pendant une connexion assistée, les actions de l'utilisateur portent aussi l'administrateur. */
final class ImpersonationAwareAuditTrailTest extends TestCase
{
    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a8073';

    public function testUserActionsAreAttributedToTheImpersonatingAdmin(): void
    {
        $inner = $this->inner();
        $now = new \DateTimeImmutable();
        $trail = new ImpersonationAwareAuditTrail($inner, $this->context(new ImpersonationSession(self::ADMIN, 'Admin (fictif)', 'u1', '/admin', $now, $now->modify('+30 minutes'))));

        $trail->record($this->record('user'));
        $trail->record($this->record('admin'));

        self::assertSame(self::ADMIN, $inner->records[0]->impersonatorId);
        self::assertSame('identity.devices_revoked', $inner->records[0]->action);
        self::assertNull($inner->records[1]->impersonatorId, 'actions admin inchangées');
    }

    public function testNothingChangesOutsideImpersonation(): void
    {
        $inner = $this->inner();
        new ImpersonationAwareAuditTrail($inner, $this->context(null))->record($this->record('user'));

        self::assertNull($inner->records[0]->impersonatorId);
    }

    private function record(string $actorType): AuditRecord
    {
        return new AuditRecord('0190f5e4-0000-7000-8000-0000000f8079', new \DateTimeImmutable(), $actorType, 'u1', 'identity.devices_revoked', 'user', 'u1');
    }

    private function context(?ImpersonationSession $session): ImpersonationContext
    {
        return new class($session) implements ImpersonationContext {
            public function __construct(private readonly ?ImpersonationSession $session)
            {
            }

            public function current(): ?ImpersonationSession
            {
                return $this->session;
            }
        };
    }

    /** @return AuditTrail&object{records: list<AuditRecord>} */
    private function inner(): AuditTrail
    {
        return new class implements AuditTrail {
            /** @var list<AuditRecord> */
            public array $records = [];

            public function record(AuditRecord $record): void
            {
                $this->records[] = $record;
            }

            public function verifyChain(): ?string
            {
                return null;
            }
        };
    }
}
