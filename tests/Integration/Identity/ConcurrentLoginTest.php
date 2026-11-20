<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\Command\RegisterUser;
use App\Identity\Application\Login\RecordLogin;
use App\Identity\Domain\User\UserId;
use App\Identity\Domain\User\UserRepository;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Deux connexions simultanées du même compte (double clic pendant l'envoi de l'alerte « nouvel appareil ») : la seconde
 * ne doit pas échouer sur le verrou optimiste du compte ; la date de dernière connexion la plus récente est gardée.
 */
final class ConcurrentLoginTest extends KernelTestCase
{
    private const string USER = '0190f5e4-0000-7000-8000-0000000e1001';

    public function testLoginSurvivesAConcurrentUpdateOfTheAccount(): void
    {
        self::bootKernel();
        $bus = self::getContainer()->get(CommandBus::class);
        $connection = self::getContainer()->get(Connection::class);
        $users = self::getContainer()->get(UserRepository::class);
        \assert($bus instanceof CommandBus && $connection instanceof Connection && $users instanceof UserRepository);
        $bus->dispatch(new RegisterUser(self::USER, 'double.clic@exemple.test', 'cheval agrafe batterie', 'client', 'Camille', 'Clic'));
        $connection->executeStatement("UPDATE identity.user_account SET status = 'active', email_verified_at = date_trunc('second', now()) WHERE id = :id", ['id' => self::USER]);

        // Le compte est chargé (version N), puis une autre requête le modifie (version N + 1) avant l'enregistrement.
        $users->get(new UserId(self::USER));
        $connection->executeStatement('UPDATE identity.user_account SET version = version + 1, last_login_at = :later WHERE id = :id', ['later' => '2099-01-01 00:00:00+00', 'id' => self::USER]);

        $bus->dispatch(new RecordLogin(self::USER, 'appareil-de-test', 'Mozilla/5.0 (fictif)', null, null, 'password'));

        self::assertSame('2099-01-01 00:00:00+00', DbalValue::string($connection->fetchOne('SELECT last_login_at FROM identity.user_account WHERE id = :id', ['id' => self::USER])), 'la date la plus récente est conservée');
        self::assertSame(1, DbalValue::int($connection->fetchOne("SELECT count(*) FROM admin.audit_log WHERE action = 'identity.login' AND subject_id = :id", ['id' => self::USER])));
    }
}
