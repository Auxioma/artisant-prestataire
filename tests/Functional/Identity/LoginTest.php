<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Application\Command\RegisterUser;
use App\Identity\Domain\User\UserId;
use App\Identity\Domain\User\UserRepository;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\Fake\AcceptingHumanVerification;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** A04 / RG-045 / E-05 : connexion, message unique, captcha puis blocage, redirection sûre, appareils, déconnexion. */
final class LoginTest extends WebTestCase
{
    private const string ID = '0190f5e4-0000-7000-8000-000000000901';
    private const string EMAIL = 'camille@exemple.test';
    private const string PASSWORD = 'cheval agrafe batterie';
    private const string GENERIC = 'Email ou mot de passe incorrect.';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        AcceptingHumanVerification::$down = false;
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost'); // CSRF sans état : en-tête Origin des navigateurs
        $this->client->setServerParameter('HTTP_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36');
        $commands = self::getContainer()->get(CommandBus::class);
        $connection = self::getContainer()->get(Connection::class);
        \assert($commands instanceof CommandBus && $connection instanceof Connection);
        $connection->executeStatement("DELETE FROM admin.setting WHERE key LIKE 'auth.%'");
        $commands->dispatch(new RegisterUser(self::ID, self::EMAIL, self::PASSWORD, 'client', 'Camille', 'Exemple'));
    }

    /** @param array<string, string> $extra */
    private function login(string $email = self::EMAIL, string $password = self::PASSWORD, array $extra = [], string $query = ''): void
    {
        $crawler = $this->client->request('GET', '/connexion'.$query);
        $token = $crawler->filter('input[name="_csrf_token"]')->attr('value');
        $hidden = $crawler->filter('input[name="_target_path"]');
        $target = $hidden->count() > 0 ? ['_target_path' => (string) $hidden->attr('value')] : [];
        $this->client->request('POST', '/connexion', $extra + $target + ['_username' => $email, '_password' => $password, '_csrf_token' => (string) $token]);
    }

    private function loginPageText(): string
    {
        return $this->client->request('GET', '/connexion')->filter('main')->text();
    }

    private function rows(string $sql): int
    {
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);

        return DbalValue::int($connection->fetchOne($sql));
    }

    public function testLoginPageMatchesTheDesign(): void
    {
        $crawler = $this->client->request('GET', '/connexion');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#connexion-titre', 'Se connecter');
        self::assertCount(1, $crawler->filter('input[name="_username"][autocomplete="username"]'));
        self::assertCount(1, $crawler->filter('input[name="_password"][autocomplete="current-password"]'));
        self::assertCount(1, $crawler->filter('input[name="_remember_me"]'));
        self::assertSelectorNotExists('.cf-turnstile', 'captcha seulement après des échecs');
        self::assertSame('/inscription', $crawler->filter('.tm-signup__login a')->attr('href'));
    }

    public function testSuccessfulLoginRecordsTheDeviceWithoutAlertingForTheFirstOne(): void
    {
        $this->login();

        self::assertResponseRedirects('/', 303);
        self::assertTrue($this->client->getResponse()->headers->has('Set-Cookie'));
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Vous êtes connecté avec '.self::EMAIL);
        self::assertSame(1, $this->rows("SELECT count(*) FROM identity.session_device WHERE description = 'Chrome sur Windows'"));
        self::assertSame(1, $this->rows('SELECT count(*) FROM identity.user_account WHERE last_login_at IS NOT NULL'));
        self::assertSame(1, $this->rows("SELECT count(*) FROM admin.audit_log WHERE action = 'identity.login'"));
        self::assertEmailCount(0);
    }

    public function testNewDeviceTriggersTheE05Alert(): void
    {
        $this->login();
        $this->client->getCookieJar()->clear(); // autre navigateur : ni session ni cookie d'appareil

        $this->login();

        self::assertResponseRedirects('/', 303);
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailHeaderSame($email, 'X-TrouveMoi-Template', 'E-05');
        self::assertEmailTextBodyContains($email, 'Chrome sur Windows');
    }

    public function testFailuresShowOneMessageWhetherTheAccountExistsOrNot(): void
    {
        $this->login(password: 'mauvais mot de passe');
        self::assertResponseRedirects('/connexion');
        $wrongPassword = $this->loginPageText();

        $this->login('inconnu@exemple.test', 'mauvais mot de passe');
        $unknown = $this->loginPageText();

        self::assertStringContainsString(self::GENERIC, $wrongPassword);
        self::assertSame(str_replace(self::EMAIL, 'X', $wrongPassword), str_replace('inconnu@exemple.test', 'X', $unknown));
        self::assertSame(2, $this->rows("SELECT count(*) FROM admin.audit_log WHERE action = 'security.login_failed' AND ip IS NOT NULL"));
    }

    public function testCaptchaAfterFiveFailuresThenBlockAfterTen(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->login(password: 'mauvais '.$i);
        }
        $crawler = $this->client->request('GET', '/connexion');
        self::assertCount(1, $crawler->filter('.cf-turnstile'), 'RG-045 : captcha après 5 échecs');

        $this->login();
        self::assertResponseRedirects('/connexion');
        self::assertStringContainsString('confirmer que vous n’êtes pas un robot', $this->loginPageText());

        for ($i = 5; $i < 9; ++$i) {
            $this->login(password: 'mauvais '.$i, extra: ['cf-turnstile-response' => AcceptingHumanVerification::HUMAN]);
        }
        $this->login(extra: ['cf-turnstile-response' => AcceptingHumanVerification::HUMAN]);
        self::assertStringContainsString('Trop de tentatives. Réessayez dans 15 minutes', $this->loginPageText());
        self::assertSame(0, $this->rows('SELECT count(*) FROM identity.session_device'));
    }

    public function testCaptchaAcceptedLetsTheRightPasswordIn(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->login(password: 'mauvais '.$i);
        }

        $this->login(extra: ['cf-turnstile-response' => AcceptingHumanVerification::HUMAN]);

        self::assertResponseRedirects('/', 303);
    }

    public function testRedirectIsLimitedToInternalPages(): void
    {
        $this->login(query: '?redirect=/cgu');
        self::assertResponseRedirects('/cgu', 303);

        $this->client->getCookieJar()->clear();
        $this->login(extra: ['_target_path' => 'https://evil.example/']);
        self::assertResponseRedirects('/', 303);

        $this->client->getCookieJar()->clear();
        foreach (['//evil.example', '/\\evil.example', '/deconnexion', '/inexistant'] as $target) {
            $crawler = $this->client->request('GET', '/connexion?redirect='.rawurlencode($target));
            self::assertCount(0, $crawler->filter('input[name="_target_path"]'), $target);
        }
    }

    public function testRememberMeCookieIsIssuedOnRequest(): void
    {
        $this->login(extra: ['_remember_me' => 'on']);

        $cookie = $this->client->getCookieJar()->get('REMEMBERME');
        self::assertNotNull($cookie);
        self::assertTrue($cookie->isHttpOnly());
        self::assertEqualsWithDelta(time() + 30 * 86400, (int) $cookie->getExpiresTime(), 120);
    }

    public function testSuspendedAccountIsOnlyRevealedToItsOwner(): void
    {
        $users = self::getContainer()->get(UserRepository::class);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($users instanceof UserRepository && $em instanceof EntityManagerInterface);
        $users->get(new UserId(self::ID))->suspend('Contrôle', new \DateTimeImmutable());
        $em->flush();

        $this->login(password: 'mauvais mot de passe');
        self::assertStringContainsString(self::GENERIC, $this->loginPageText());

        $this->login();
        self::assertStringContainsString('Ce compte n’est pas accessible', $this->loginPageText());
    }

    public function testLogoutRequiresItsTokenAndSignedInUsersSkipTheForm(): void
    {
        $this->login();
        $this->client->request('GET', '/connexion');
        self::assertResponseRedirects('/');

        $this->client->request('GET', '/deconnexion');
        self::assertResponseStatusCodeSame(403);

        $link = $this->client->request('GET', '/')->filter('a[href^="/deconnexion?_csrf_token="]')->first()->attr('href');
        $this->client->request('GET', (string) $link);
        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Bienvenue sur TrouveMoi');
    }
}
