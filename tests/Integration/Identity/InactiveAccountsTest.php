<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\Command\RegisterUser;
use App\Identity\Application\Privacy\EraseDueAccounts;
use App\Identity\Application\Privacy\NoticeInactiveAccounts;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** T-808b1 — CDC §11 : inactif 3 ans → relance E-09b ; sans connexion sous 14 jours → effacement (E-09 « inactivité »). */
final class InactiveAccountsTest extends KernelTestCase
{
    private const string SLEEPER = '0190f5e4-0000-7000-8000-0000000f8081';
    private const string RETURNING = '0190f5e4-0000-7000-8000-0000000f8082';
    private const string RECENT = '0190f5e4-0000-7000-8000-0000000f8083';

    private Connection $connection;
    private CommandBus $bus;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $bus = self::getContainer()->get(CommandBus::class);
        \assert($connection instanceof Connection && $bus instanceof CommandBus);
        [$this->connection, $this->bus] = [$connection, $bus];
        foreach ([self::SLEEPER => 'dormeur', self::RETURNING => 'revenant', self::RECENT => 'recent'] as $id => $name) {
            $bus->dispatch(new RegisterUser($id, $name.'.808@exemple.test', 'cheval agrafe batterie', 'client', ucfirst($name), 'Fictif'));
        }
        $connection->executeStatement("UPDATE identity.user_account SET status = 'active', email_verified_at = date_trunc('second', now()) WHERE id IN (:a, :b, :c)", ['a' => self::SLEEPER, 'b' => self::RETURNING, 'c' => self::RECENT]);
        $connection->executeStatement("UPDATE identity.user_account SET created_at = date_trunc('second', now() - interval '4 years'), last_login_at = date_trunc('second', now() - interval '37 months') WHERE id IN (:a, :b)", ['a' => self::SLEEPER, 'b' => self::RETURNING]);
        $connection->executeStatement("UPDATE identity.user_account SET last_login_at = date_trunc('second', now() - interval '2 years') WHERE id = :c", ['c' => self::RECENT]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    public function testInactiveAccountsAreNoticedThenErasedUnlessTheyComeBack(): void
    {
        $this->bus->dispatch(new NoticeInactiveAccounts());
        self::assertNotNull($this->value('inactivity_notice_at', self::SLEEPER));
        self::assertNotNull($this->value('inactivity_notice_at', self::RETURNING));
        self::assertNull($this->value('inactivity_notice_at', self::RECENT), 'actif il y a 2 ans : pas concerné');
        self::assertSame(2, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM notification.delivery WHERE code = 'E-09b' AND status <> 'failed'")), 'deux comptes relancés, modèle rendu');

        // Relancé une seule fois.
        $this->bus->dispatch(new NoticeInactiveAccounts());
        self::assertSame(2, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM notification.delivery WHERE code = 'E-09b'")));

        // 15 jours plus tard : l'un s'est reconnecté (la connexion efface la relance), l'autre non.
        $this->connection->executeStatement("UPDATE identity.user_account SET inactivity_notice_at = date_trunc('second', now() - interval '15 days') WHERE id IN (:a, :b)", ['a' => self::SLEEPER, 'b' => self::RETURNING]);
        $this->connection->executeStatement("UPDATE identity.user_account SET last_login_at = date_trunc('second', now() - interval '3 days'), inactivity_notice_at = NULL WHERE id = :b", ['b' => self::RETURNING]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->bus->dispatch(new NoticeInactiveAccounts());
        self::assertSame('deletion_requested', $this->value('status', self::SLEEPER));
        self::assertSame($this->value('inactivity_notice_at', self::SLEEPER), $this->value('deletion_requested_at', self::SLEEPER), 'effacement daté de la relance');
        self::assertSame('active', $this->value('status', self::RETURNING));

        $this->bus->dispatch(new EraseDueAccounts());
        self::assertSame('anonymized', $this->value('status', self::SLEEPER));
        self::assertSame(1, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM admin.audit_log WHERE action = 'gdpr.inactivity_erasure_scheduled' AND subject_id = :a", ['a' => self::SLEEPER])));
        self::assertSame(1, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM notification.delivery WHERE code = 'E-09' AND recipient_masked LIKE 'd***%'")));
    }

    public function testALoginClearsThePendingNotice(): void
    {
        $this->bus->dispatch(new NoticeInactiveAccounts());
        $users = self::getContainer()->get(\App\Identity\Domain\User\UserRepository::class);
        \assert($users instanceof \App\Identity\Domain\User\UserRepository);
        $user = $users->get(new \App\Identity\Domain\User\UserId(self::RETURNING));
        self::assertNotNull($user->inactivityNoticeAt());
        $user->recordLogin(new \DateTimeImmutable());
        self::assertNull($user->inactivityNoticeAt());
    }

    private function value(string $column, string $id): mixed
    {
        return $this->connection->fetchOne('SELECT '.$column.' FROM identity.user_account WHERE id = :id', ['id' => $id]);
    }
}
