<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Application\Command\RegisterUser;
use App\Identity\Application\EventHandler\SendPasswordChangedConfirmation;
use App\Identity\Domain\User\Event\UserPasswordChanged;
use App\Identity\Domain\User\UserId;
use App\Identity\Domain\User\UserRepository;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

/** A05 / E-03 / E-04 : réponse identique, quotas, lien unique d'une heure, sessions fermées, connexion automatique. */
final class PasswordResetTest extends WebTestCase
{
    private const string ID = '0190f5e4-0000-7000-8000-000000000a01';
    private const string EMAIL = 'camille@exemple.test';
    private const string OLD = 'cheval agrafe batterie';
    private const string NEW = 'nouvelle phrase secrète';
    private const string SENT = 'Si un compte existe pour cette adresse';

    private KernelBrowser $client;
    /** @var list<Email> E-mails envoyés pendant le test (le journal du Mailer est remis à zéro à chaque requête) */
    private array $sent = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $commands = self::getContainer()->get(CommandBus::class);
        $connection = self::getContainer()->get(Connection::class);
        \assert($commands instanceof CommandBus && $connection instanceof Connection);
        $connection->executeStatement("DELETE FROM admin.setting WHERE key LIKE 'auth.%'");
        $commands->dispatch(new RegisterUser(self::ID, self::EMAIL, self::OLD, 'client', 'Camille', 'Exemple'));
    }

    private function requestLink(string $email = self::EMAIL): void
    {
        $crawler = $this->client->request('GET', '/mot-de-passe-oublie');
        $this->client->request('POST', '/mot-de-passe-oublie', ['email' => $email, '_token' => (string) $crawler->filter('input[name="_token"]')->attr('value')]);
        foreach (self::getMailerMessages() as $message) {
            if ($message instanceof Email) {
                $this->sent[] = $message;
            }
        }
    }

    /** @return list<Email> */
    private function resetMails(): array
    {
        return array_values(array_filter($this->sent, static fn (Email $m): bool => 'E-03' === $m->getHeaders()->getHeaderBody('X-TrouveMoi-Template')));
    }

    private function lastLink(): string
    {
        $mails = $this->resetMails();
        $mail = end($mails);
        self::assertInstanceOf(Email::class, $mail);
        self::assertSame(1, preg_match('#(/reinitialiser/[A-Za-z0-9_-]{43})#', (string) $mail->getTextBody(), $m));

        return $m[1];
    }

    private function submitNewPassword(string $link, string $password, ?string $confirmation = null): void
    {
        $crawler = $this->client->request('GET', $link);
        $form = $crawler->selectButton('Enregistrer et me connecter')->form([
            'password_reset[password][first]' => $password,
            'password_reset[password][second]' => $confirmation ?? $password,
        ]);
        $this->client->submit($form);
    }

    private function login(string $password): void
    {
        $crawler = $this->client->request('GET', '/connexion');
        $this->client->request('POST', '/connexion', ['_username' => self::EMAIL, '_password' => $password, '_csrf_token' => (string) $crawler->filter('input[name="_csrf_token"]')->attr('value')]);
    }

    public function testSameAnswerWhetherTheAccountExistsOrNot(): void
    {
        $this->requestLink();
        self::assertResponseIsSuccessful();
        $known = $this->client->getCrawler()->filter('main')->text();

        $this->requestLink('personne@exemple.test');
        self::assertResponseIsSuccessful();
        $unknown = $this->client->getCrawler()->filter('main')->text();

        self::assertStringContainsString(self::SENT, $known);
        self::assertSame(str_replace(self::EMAIL, 'X', $known), str_replace('personne@exemple.test', 'X', $unknown));
        self::assertCount(1, $this->resetMails(), 'seul le titulaire reçoit un lien');
        self::assertSame(self::EMAIL, $this->resetMails()[0]->getTo()[0]->getAddress());
    }

    public function testInvalidAddressAndForgedTokenAreRejected(): void
    {
        $this->requestLink('pas-une-adresse');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.tm-field__error', 'Adresse email invalide.');

        $this->client->request('POST', '/mot-de-passe-oublie', ['email' => self::EMAIL, '_token' => 'faux']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAtMostThreeLinksPerHourAndAccount(): void
    {
        for ($i = 0; $i < 4; ++$i) {
            $this->requestLink();
            self::assertSelectorTextContains('.tm-form-success', self::SENT);
        }

        self::assertCount(3, $this->resetMails());
    }

    public function testSuspendedAccountsReceiveNothing(): void
    {
        $users = self::getContainer()->get(UserRepository::class);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($users instanceof UserRepository && $em instanceof EntityManagerInterface);
        $users->get(new UserId(self::ID))->suspend('Contrôle', new \DateTimeImmutable());
        $em->flush();

        $this->requestLink();

        self::assertSelectorTextContains('.tm-form-success', self::SENT);
        self::assertCount(0, $this->resetMails());
    }

    public function testResetSignsInClosesOtherSessionsAndBurnsTheLink(): void
    {
        $this->login(self::OLD);
        $openedSession = $this->client->getCookieJar()->get('MOCKSESSID');
        self::assertNotNull($openedSession, 'session ouverte avant la réinitialisation');
        $this->requestLink();
        $link = $this->lastLink();
        $this->client->getCookieJar()->clear();

        $this->submitNewPassword($link, 'court');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.tm-field__error', 'au moins 12 caractères');

        $this->submitNewPassword($link, self::NEW, self::NEW.'x');
        self::assertSelectorTextContains('.tm-field__error', 'ne correspondent pas');

        $this->submitNewPassword($link, self::NEW);
        self::assertResponseRedirects('/', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.tm-dropdown__title', self::EMAIL, 'en-tête du compte connecté');

        $this->client->request('GET', $link);
        self::assertResponseStatusCodeSame(410);
        self::assertSelectorTextContains('h1', 'Ce lien a expiré');

        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        self::assertSame(1, DbalValue::int($connection->fetchOne("SELECT count(*) FROM admin.audit_log WHERE action = 'identity.password_reset'")));
        self::assertSame('active', DbalValue::string($connection->fetchOne("SELECT status FROM identity.user_account WHERE id = '".self::ID."'")), 'le lien prouve aussi l’adresse');

        // Session ouverte AVANT la réinitialisation (autre appareil) : redevenue anonyme.
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set($openedSession);
        $this->client->request('GET', '/');
        self::assertSelectorExists('header a[href="/connexion"]', 'en-tête visiteur (déconnecté)');
    }

    public function testOldPasswordStopsWorkingAndExpiredLinksOfferANewOne(): void
    {
        $this->requestLink();
        $link = $this->lastLink();
        $this->submitNewPassword($link, self::NEW);
        $this->client->getCookieJar()->clear();

        $this->login(self::OLD);
        self::assertResponseRedirects('/connexion');
        $this->login(self::NEW);
        self::assertResponseRedirects('/espace-client', 303);

        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $this->requestLink();
        $connection->executeStatement("UPDATE identity.password_reset_token SET created_at = now() - interval '2 hours', expires_at = now() - interval '1 hour' WHERE used_at IS NULL AND revoked_at IS NULL");
        $crawler = $this->client->request('GET', $this->lastLink());
        self::assertResponseStatusCodeSame(410);
        self::assertSame('/mot-de-passe-oublie', $crawler->selectLink('Renvoyer un lien')->attr('href'));
    }

    public function testPasswordChangeConfirmationE04(): void
    {
        $handler = self::getContainer()->get(SendPasswordChangedConfirmation::class);
        \assert($handler instanceof SendPasswordChangedConfirmation);

        $handler(new UserPasswordChanged(self::ID, new \DateTimeImmutable()));

        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertSame('E-04', $mail->getHeaders()->getHeaderBody('X-TrouveMoi-Template'));
        self::assertSame(self::EMAIL, $mail->getTo()[0]->getAddress());
    }
}
