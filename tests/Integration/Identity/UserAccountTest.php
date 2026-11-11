<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\Command\ChangePassword;
use App\Identity\Application\Command\EmailAlreadyRegistered;
use App\Identity\Application\Command\InvalidCurrentPassword;
use App\Identity\Application\Command\RegisterUser;
use App\Identity\Application\Password\WeakPassword;
use App\Identity\Domain\User\Event\UserRegistered;
use App\Identity\Domain\User\UserId;
use App\Identity\Domain\User\UserRepository;
use App\Identity\Infrastructure\Security\UserProvider;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

/** T-201 : inscription, unicité de l'e-mail, Argon2id, politique de mot de passe, fournisseur Symfony. */
final class UserAccountTest extends KernelTestCase
{
    private const string ID = '0190f5e4-0000-7000-8000-000000000201';
    private const string PASSWORD = 'cheval agrafe batterie';

    private CommandBus $commands;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $commands = self::getContainer()->get(CommandBus::class);
        $connection = self::getContainer()->get(Connection::class);
        \assert($commands instanceof CommandBus && $connection instanceof Connection);
        [$this->commands, $this->connection] = [$commands, $connection];
        $this->connection->executeStatement("DELETE FROM admin.setting WHERE key = 'auth.password_min_length'");
    }

    private function register(string $id = self::ID, string $email = 'Camille.Exemple@exemple.test', string $password = self::PASSWORD): void
    {
        $this->commands->dispatch(new RegisterUser($id, $email, $password, 'client', 'Camille', 'Exemple', '203.0.113.7'));
    }

    /** @param array<string, string> $params */
    private function scalar(string $sql, array $params = []): string
    {
        $value = $this->connection->fetchOne($sql, $params);

        return \is_int($value) ? (string) $value : DbalValue::string($value);
    }

    private function provider(): UserProvider
    {
        $provider = self::getContainer()->get(UserProvider::class);
        \assert($provider instanceof UserProvider);

        return $provider;
    }

    public function testRegistrationStoresArgon2idHashAndPublishesEvent(): void
    {
        $this->register();

        $row = $this->connection->fetchAssociative('SELECT * FROM identity.user_account WHERE id = :id', ['id' => self::ID]);
        self::assertIsArray($row);
        self::assertSame(['Camille.Exemple@exemple.test', 'camille.exemple@exemple.test', 'pending_email', 'client'], [$row['email'], $row['email_canonical'], $row['status'], $row['kind']]);
        self::assertIsString($row['password_hash']);
        self::assertStringStartsWith('$argon2id$', $row['password_hash']);
        self::assertStringNotContainsString(self::PASSWORD, (string) json_encode($row));
        self::assertSame('1', $this->scalar('SELECT count(*) FROM shared.outbox_message WHERE message_type = :t', ['t' => UserRegistered::class]));
        self::assertStringNotContainsString('exemple.test', $this->scalar('SELECT body FROM shared.outbox_message WHERE message_type = :t', ['t' => UserRegistered::class]));
        self::assertSame('1', $this->scalar("SELECT count(*) FROM admin.audit_log WHERE action = 'identity.user_registered' AND subject_id = :id", ['id' => self::ID]));
    }

    public function testEmailIsUniqueRegardlessOfCase(): void
    {
        $this->register();

        $this->expectException(EmailAlreadyRegistered::class);
        $this->register('0190f5e4-0000-7000-8000-000000000202', 'CAMILLE.EXEMPLE@EXEMPLE.TEST');
    }

    public function testDatabaseEnforcesUniquenessAndRefusesPlainPasswords(): void
    {
        $this->register();
        $insert = "INSERT INTO identity.user_account (id, email, email_canonical, password_hash, kind, status, first_name, last_name, password_changed_at, created_at, updated_at) VALUES (:id, 'x@exemple.test', :canonical, :hash, 'client', 'pending_email', 'A', 'B', now(), now(), now())";

        $refused = [];
        foreach (['doublon' => ['canonical' => 'camille.exemple@exemple.test', 'hash' => '$argon2id$x'], 'en clair' => ['canonical' => 'autre@exemple.test', 'hash' => 'en-clair']] as $case => $values) {
            $this->connection->beginTransaction();
            try {
                $this->connection->executeStatement($insert, ['id' => '0190f5e4-0000-7000-8000-0000000003'.\count($refused).'0'] + $values);
            } catch (\Doctrine\DBAL\Exception) {
                $refused[] = $case;
            } finally {
                $this->connection->rollBack();
            }
        }
        self::assertSame(['doublon', 'en clair'], $refused);
    }

    public function testWeakPasswordIsRefusedBeforeAnyStorage(): void
    {
        try {
            $this->register(password: 'trop-court');
            self::fail('Mot de passe court accepté');
        } catch (WeakPassword $e) {
            self::assertSame(WeakPassword::TOO_SHORT, $e->reason);
            self::assertSame(12, $e->minLength);
        }
        self::assertSame('0', $this->scalar('SELECT count(*) FROM identity.user_account'));
    }

    public function testProviderLoadsByEmailCaseInsensitivelyAndRefreshes(): void
    {
        $this->register();

        $user = $this->provider()->loadUserByIdentifier('CAMILLE.exemple@EXEMPLE.test');
        self::assertSame(self::ID, $user->id);
        self::assertSame(['ROLE_USER', 'ROLE_CLIENT'], $user->getRoles());
        $factory = self::getContainer()->get(PasswordHasherFactoryInterface::class);
        \assert($factory instanceof PasswordHasherFactoryInterface);
        self::assertTrue($factory->getPasswordHasher($user)->verify($user->getPassword(), self::PASSWORD));

        $restored = unserialize(serialize($user));
        \assert($restored instanceof \App\Identity\Infrastructure\Security\SecurityUser);
        self::assertTrue($restored->isEqualTo($this->provider()->refreshUser($restored)));

        $notFound = [];
        foreach (['inconnu@exemple.test', 'pas-un-email'] as $identifier) {
            try {
                $this->provider()->loadUserByIdentifier($identifier);
            } catch (UserNotFoundException) {
                $notFound[] = $identifier;
            }
        }
        self::assertSame(['inconnu@exemple.test', 'pas-un-email'], $notFound);
    }

    public function testChangePasswordRequiresCurrentPasswordAndClosesSessions(): void
    {
        $this->register();
        $session = unserialize(serialize($this->provider()->loadUserByIdentifier('camille.exemple@exemple.test')));
        \assert($session instanceof \App\Identity\Infrastructure\Security\SecurityUser);

        try {
            $this->commands->dispatch(new ChangePassword(self::ID, 'mauvais mot de passe', 'nouvelle phrase secrète'));
            self::fail('Mot de passe actuel non vérifié');
        } catch (InvalidCurrentPassword) {
        }

        $this->commands->dispatch(new ChangePassword(self::ID, self::PASSWORD, 'nouvelle phrase secrète'));

        self::assertFalse($session->isEqualTo($this->provider()->refreshUser($session)), 'session antérieure invalidée');
        self::assertSame('1', $this->scalar("SELECT count(*) FROM admin.audit_log WHERE action = 'identity.password_changed' AND subject_id = :id", ['id' => self::ID]));
    }

    public function testAnonymizedAccountReleasesItsEmail(): void
    {
        $this->register();
        $users = self::getContainer()->get(UserRepository::class);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($users instanceof UserRepository && $em instanceof EntityManagerInterface);
        $users->get(new UserId(self::ID))->anonymize(new \DateTimeImmutable());
        $em->flush();

        $this->register('0190f5e4-0000-7000-8000-000000000203');

        self::assertSame('2', $this->scalar("SELECT count(*) FROM identity.user_account WHERE email_canonical LIKE '%' "));
        $this->expectException(UserNotFoundException::class);
        $this->provider()->loadUserByIdentifier('compte-'.self::ID.'@anonymise.invalid');
    }
}
