<?php

declare(strict_types=1);

namespace App\Tests\Integration\Administration;

use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** BO15 / cadrage §5.8 : journal d'audit append-only, chaîne de hash vérifiable. */
final class AuditTrailTest extends KernelTestCase
{
    private AuditTrail $trail;
    private Connection $connection;
    private IdGenerator $ids;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->trail = $container->get(AuditTrail::class);
        $this->connection = $container->get(Connection::class);
        $this->ids = $container->get(IdGenerator::class);
    }

    private function record(string $action): AuditRecord
    {
        return new AuditRecord($this->ids->next(), new \DateTimeImmutable(), 'system', null, $action, context: ['n' => $action]);
    }

    public function testChainIsIntactAfterSeveralRecords(): void
    {
        $this->trail->record($this->record('identity.login_succeeded'));
        $this->trail->record($this->record('billing.credits_adjusted'));
        $this->trail->record($this->record('artisan.document_viewed'));

        self::assertNull($this->trail->verifyChain());
        self::assertSame(2, DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM admin.audit_log WHERE prev_hash IS NOT NULL')));
    }

    public function testUpdateAndDeleteAreRejectedByDatabase(): void
    {
        $this->trail->record($this->record('identity.login_succeeded'));

        $this->expectExceptionMessageMatches('/ajout seul/');
        $this->connection->executeStatement('DELETE FROM admin.audit_log');
    }

    public function testVerificationDetectsTamperingEvenIfTriggerIsBypassed(): void
    {
        $first = $this->record('identity.login_succeeded');
        $this->trail->record($first);
        $this->trail->record($this->record('billing.credits_adjusted'));

        // Simule un attaquant disposant des droits du propriétaire (annulé par la transaction de test).
        $this->connection->executeStatement('ALTER TABLE admin.audit_log DISABLE TRIGGER audit_log_append_only');
        $this->connection->executeStatement("UPDATE admin.audit_log SET canonical = replace(canonical, 'identity.login_succeeded', 'identity.login_failed') WHERE id = :id", ['id' => $first->id]);

        self::assertSame($first->id, $this->trail->verifyChain());
    }
}
