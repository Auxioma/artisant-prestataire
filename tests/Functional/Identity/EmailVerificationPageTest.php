<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Application\Command\RegisterUser;
use App\Identity\Application\EventHandler\SendEmailVerificationOnRegistration;
use App\Identity\Domain\User\Event\UserRegistered;
use App\Identity\Infrastructure\Security\UserProvider;
use App\Shared\Application\Bus\CommandBus;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

/** A06 : pages d'état (Figma 230:12568), renvoi protégé, bandeau des espaces tant que l'adresse n'est pas confirmée. */
final class EmailVerificationPageTest extends WebTestCase
{
    private const string ID = '0190f5e4-0000-7000-8000-000000000801';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $commands = self::getContainer()->get(CommandBus::class);
        \assert($commands instanceof CommandBus);
        $commands->dispatch(new RegisterUser(self::ID, 'camille@exemple.test', 'cheval agrafe batterie', 'client', 'Camille', 'Exemple'));
    }

    private function link(): string
    {
        $handler = self::getContainer()->get(SendEmailVerificationOnRegistration::class);
        \assert($handler instanceof SendEmailVerificationOnRegistration);
        $handler(new UserRegistered(self::ID, 'client', new \DateTimeImmutable()));
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertSame(1, preg_match('#https?://[^/\s]+(/verification-email/[A-Za-z0-9_-]{43})#', (string) $mail->getTextBody(), $m));

        return $m[1];
    }

    public function testSuccessThenAlreadyVerified(): void
    {
        $link = $this->link();

        $this->client->request('GET', $link);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Adresse email confirmée');
        self::assertSelectorExists('meta[name="referrer"][content="no-referrer"]');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSelectorExists('[data-analytics-auto-event-value="email_verified"]');

        $this->client->request('GET', $link);
        self::assertSelectorTextContains('h1', 'Adresse déjà validée');
    }

    public function testExpiredLinkOffersAResendWithCsrfProtection(): void
    {
        $link = $this->link();
        $connection = self::getContainer()->get(\Doctrine\DBAL\Connection::class);
        \assert($connection instanceof \Doctrine\DBAL\Connection);
        $connection->executeStatement("UPDATE identity.email_verification_token SET created_at = now() - interval '2 days', expires_at = now() - interval '1 day'");

        $crawler = $this->client->request('GET', $link);
        self::assertSelectorTextContains('h1', 'Lien expiré');

        $this->client->request('POST', '/verification-email/renvoyer', ['_token' => 'faux', 'token' => substr($link, -43)]);
        self::assertResponseStatusCodeSame(403);

        $this->client->submit($crawler->selectButton('Renvoyer le lien')->form());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Vérifiez votre boîte mail');
        self::assertEmailCount(1);
    }

    public function testUnknownLinkIsNotFound(): void
    {
        $this->client->request('GET', '/verification-email/'.str_repeat('A', 43));

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('h1', 'Lien invalide');
    }

    public function testBannerIsShownToSignedInUnverifiedUsersOnly(): void
    {
        $provider = self::getContainer()->get(UserProvider::class);
        \assert($provider instanceof UserProvider);
        $this->client->loginUser($provider->loadUserByIdentifier('camille@exemple.test'));

        $this->client->request('GET', '/inscription');
        self::assertSelectorTextContains('.tm-verify-banner', 'Confirmez votre email');

        $this->client->request('GET', $this->link());
        $this->client->loginUser($provider->loadUserByIdentifier('camille@exemple.test'));
        $this->client->request('GET', '/inscription');
        self::assertSelectorNotExists('.tm-verify-banner');
    }
}
