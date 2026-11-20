<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Application\Command\RegisterUser;
use App\Notification\Application\Notification;
use App\Notification\Application\Notifier;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/** T-705c2 — C07.2 « Sécurité » (e-mail, mot de passe, appareils) et C07.3 « Mes paramètres » (matrice sujet × canal). */
final class ClientSecurityAndSettingsTest extends WebTestCase
{
    use ArtisanDossier;

    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000f7801';
    private const string OTHER_DEVICE = '0190f5e4-0000-7000-8000-0000000d7801';
    private const string PASSWORD = 'cheval agrafe batterie';

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $this->connection = $connection;
        $this->prepareReferentials();
        $this->dossierDispatch(new RegisterUser(self::CLIENT, 'camille.c072@exemple.test', self::PASSWORD, 'client', 'Camille', 'Fictive'));
        $connection->executeStatement("UPDATE identity.user_account SET status = 'active', email_verified_at = date_trunc('second', now()) WHERE id = :c", ['c' => self::CLIENT]);
        $connection->executeStatement("INSERT INTO identity.client_profile (user_id, type, postal_code, created_at, updated_at) VALUES (:c, 'particulier', '76600', date_trunc('second', now()), date_trunc('second', now()))", ['c' => self::CLIENT]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $this->login(self::PASSWORD);
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    private function login(string $password): void
    {
        $crawler = $this->client->request('GET', '/connexion');
        $this->client->request('POST', '/connexion', ['_username' => 'camille.c072@exemple.test', '_password' => $password, '_csrf_token' => (string) $crawler->filter('input[name="_csrf_token"]')->attr('value')]);
    }

    public function testDevicesAreListedAndRevoked(): void
    {
        $this->connection->executeStatement("INSERT INTO identity.session_device (id, user_id, device_hash, description, first_seen_at, last_seen_at) VALUES (:id, :u, repeat('a', 64), 'Firefox sur Linux (fictif)', now() - interval '3 days', now() - interval '2 days')", ['id' => self::OTHER_DEVICE, 'u' => self::CLIENT]);
        $page = $this->client->request('GET', '/espace-client/securite');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $page->filter('.tm-account__devices li'));
        self::assertStringContainsString('Cet appareil', $page->filter('.tm-account__devices li')->first()->text());

        $this->client->submit($page->filter('.tm-account__devices li')->last()->filter('form')->form());
        self::assertNotNull($this->connection->fetchOne('SELECT revoked_at FROM identity.session_device WHERE id = :id', ['id' => self::OTHER_DEVICE]));
        $page = $this->client->followRedirect();
        self::assertCount(1, $page->filter('.tm-account__devices li'));

        // L'appareil courant révoqué (depuis un autre appareil) : session fermée à la requête suivante.
        $this->connection->executeStatement('UPDATE identity.session_device SET revoked_at = now() WHERE user_id = :u', ['u' => self::CLIENT]);
        $this->client->request('GET', '/espace-client/securite');
        self::assertResponseRedirects('/connexion');
        $this->client->request('GET', '/espace-client/securite');
        self::assertResponseRedirects();
        self::assertStringContainsString('/connexion', (string) $this->client->getResponse()->headers->get('Location'));
        // Nouvelle connexion : l'appareil est réactivé.
        $this->login(self::PASSWORD);
        $this->client->request('GET', '/espace-client/securite');
        self::assertResponseIsSuccessful();
    }

    public function testChangePasswordAndEmail(): void
    {
        $page = $this->client->request('GET', '/espace-client/securite');
        $form = $page->selectButton('Changer de mot de passe')->form();
        $this->client->submit($form, ['currentPassword' => 'mauvais mot de passe', 'newPassword' => 'lanterne violette ruisseau', 'confirmPassword' => 'lanterne violette ruisseau']);
        self::assertResponseStatusCodeSame(422);
        $page = $this->client->getCrawler();
        self::assertSame('Mot de passe actuel incorrect.', $page->filter('#currentPassword_error')->text());
        $this->client->submit($page->selectButton('Changer de mot de passe')->form(), ['currentPassword' => self::PASSWORD, 'newPassword' => 'lanterne violette ruisseau', 'confirmPassword' => 'autre chose']);
        self::assertResponseStatusCodeSame(422);
        $this->client->submit($this->client->getCrawler()->selectButton('Changer de mot de passe')->form(), ['currentPassword' => self::PASSWORD, 'newPassword' => 'lanterne violette ruisseau', 'confirmPassword' => 'lanterne violette ruisseau']);
        self::assertResponseRedirects('/espace-client/securite');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful('la session courante reste ouverte');

        $page = $this->client->getCrawler();
        $this->client->submit($page->selectButton('Changer d’adresse e-mail')->form(), ['newEmail' => 'camille.nouvelle@exemple.test', 'emailPassword' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(422, 'ancien mot de passe refusé');
        $this->client->submit($this->client->getCrawler()->selectButton('Changer d’adresse e-mail')->form(), ['newEmail' => 'camille.nouvelle@exemple.test', 'emailPassword' => 'lanterne violette ruisseau']);
        self::assertResponseRedirects('/espace-client/securite');
        self::assertSame(1, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM identity.email_verification_token WHERE user_id = :u AND purpose = 'email_change'", ['u' => self::CLIENT])));
        self::assertSame('camille.c072@exemple.test', DbalValue::string($this->connection->fetchOne('SELECT email FROM identity.user_account WHERE id = :u', ['u' => self::CLIENT])));
    }

    public function testNotificationMatrixIsRespected(): void
    {
        $page = $this->client->request('GET', '/espace-client/parametres');
        self::assertResponseIsSuccessful();
        self::assertCount(10, $page->filter('.tm-account__matrix input[name^="matrix"][checked]'));
        self::assertCount(6, $page->filter('.tm-account__matrix .is-locked input[disabled]'));
        $form = $page->filter('form.tm-account__card')->form();
        $matrix = $form->get('matrix');
        \assert(\is_array($matrix) && \is_array($matrix['messages']));
        foreach ($matrix['messages'] as $box) {
            \assert($box instanceof ChoiceFormField);
            $box->untick();
        }
        $this->client->submit($form);
        self::assertResponseRedirects('/espace-client/parametres');
        $page = $this->client->followRedirect();
        self::assertCount(8, $page->filter('.tm-account__matrix input[name^="matrix"][checked]'));

        $notifier = self::getContainer()->get(Notifier::class);
        \assert($notifier instanceof Notifier);
        $notifier->notify(new Notification('E-17', 'camille.c072@exemple.test', self::CLIENT, ['prenom' => 'Camille'], '/espace-client/messages'));
        self::assertSame('skipped', DbalValue::string($this->connection->fetchOne("SELECT status FROM notification.delivery WHERE code = 'E-17' AND channel = 'email'")));
        self::assertSame(0, DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM notification.in_app WHERE user_id = :u', ['u' => self::CLIENT])));
    }
}
