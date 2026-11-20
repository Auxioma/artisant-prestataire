<?php

declare(strict_types=1);

namespace App\Tests\Integration\Administration;

use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** docs/incidents.md (T-905c) : `app:audit:verify` signale une altération du journal d'audit par son code de sortie. */
final class VerifyAuditChainCommandTest extends KernelTestCase
{
    public function testReportsIntactThenTamperedChain(): void
    {
        $kernel = self::bootKernel();
        $trail = self::getContainer()->get(AuditTrail::class);
        $ids = self::getContainer()->get(IdGenerator::class);
        $connection = self::getContainer()->get(Connection::class);
        \assert($trail instanceof AuditTrail && $ids instanceof IdGenerator && $connection instanceof Connection);
        $first = new AuditRecord($ids->next(), new \DateTimeImmutable(), 'system', null, 'identity.login_succeeded');
        $trail->record($first);
        $trail->record(new AuditRecord($ids->next(), new \DateTimeImmutable(), 'system', null, 'billing.credits_adjusted'));
        $command = new CommandTester(new Application($kernel)->find('app:audit:verify'));

        self::assertSame(Command::SUCCESS, $command->execute([]));
        self::assertStringContainsString('intègre', $command->getDisplay());

        // Attaquant disposant des droits du propriétaire (annulé par la transaction de test).
        $connection->executeStatement('ALTER TABLE admin.audit_log DISABLE TRIGGER audit_log_append_only');
        $connection->executeStatement("UPDATE admin.audit_log SET canonical = canonical || ' ' WHERE id = :id", ['id' => $first->id]);

        self::assertSame(Command::FAILURE, $command->execute([]));
        self::assertStringContainsString($first->id, $command->getDisplay());
    }
}
