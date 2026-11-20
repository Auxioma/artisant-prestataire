<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notification;

use App\Identity\Application\Command\RegisterUser;
use App\Notification\Application\Center\PurgeNotifications;
use App\Notification\Application\Port\InAppNotifications;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** T-705b — C08 « Notifications » : liste, filtre par famille, ouverture (lecture + lien), tout marquer comme lu, purge 90 jours. */
final class NotificationCenterTest extends WebTestCase
{
    use ArtisanDossier;

    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000f7601';
    private const string N_QUOTE = '0190f5e4-0000-7000-8000-0000000e7601';
    private const string N_MESSAGE = '0190f5e4-0000-7000-8000-0000000e7602';
    private const string N_ACCOUNT = '0190f5e4-0000-7000-8000-0000000e7603';
    private const string N_OLD = '0190f5e4-0000-7000-8000-0000000e7604';
    private const string N_OTHER = '0190f5e4-0000-7000-8000-0000000e7605';
    private const string N_PRO = '0190f5e4-0000-7000-8000-0000000e7606';

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $this->connection = $connection;
        $this->dossierDispatch(new RegisterUser(self::CLIENT, 'camille.c08@exemple.test', 'cheval agrafe batterie', 'client', 'Camille', 'Fictive'));
        $connection->executeStatement("UPDATE identity.user_account SET status = 'active', email_verified_at = date_trunc('second', now()) WHERE id = :c", ['c' => self::CLIENT]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    private function inbox(): InAppNotifications
    {
        $inbox = self::getContainer()->get(InAppNotifications::class);
        \assert($inbox instanceof InAppNotifications);

        return $inbox;
    }

    private function login(string $email): void
    {
        $crawler = $this->client->request('GET', '/connexion');
        $this->client->request('POST', '/connexion', ['_username' => $email, '_password' => 'cheval agrafe batterie', '_csrf_token' => (string) $crawler->filter('input[name="_csrf_token"]')->attr('value')]);
    }

    private function unread(string $userId): int
    {
        return $this->inbox()->unreadCount($userId);
    }

    public function testClientListFilterOpenReadAllAndPurge(): void
    {
        $now = new \DateTimeImmutable();
        $inbox = $this->inbox();
        $inbox->add(self::N_QUOTE, self::CLIENT, 'E-10', 'Nouveau devis reçu de Atelier Fictif', 'https://trouvemoi.example/espace-client/devis?x=1#d', $now->modify('-12 minutes'));
        $inbox->add(self::N_MESSAGE, self::CLIENT, 'E-17', 'Atelier Fictif vous a envoyé un message', 'https://autre.example//evil', $now->modify('-3 hours'));
        $inbox->add(self::N_ACCOUNT, self::CLIENT, 'E-03', 'Votre mot de passe a été modifié', null, $now->modify('-3 days'));
        $inbox->add(self::N_OLD, self::CLIENT, 'E-17', 'Ancien message (fictif)', null, $now->modify('-91 days'));
        $inbox->add(self::N_OTHER, self::DOSSIER_USER, 'E-10', 'Notification d’un autre compte', null, $now);
        $this->login('camille.c08@exemple.test');

        $page = $this->client->request('GET', '/espace-client/notifications');
        self::assertResponseIsSuccessful();
        self::assertCount(4, $page->filter('.tm-notif__list li'));
        self::assertCount(4, $page->filter('.tm-notif__list li.is-unread'));
        self::assertStringNotContainsString('autre compte', $page->filter('.tm-notif__list')->text());
        self::assertStringContainsString('Il y a 12 min', $page->filter('.tm-notif__list li')->first()->text());

        $page = $this->client->request('GET', '/espace-client/notifications?type=devis');
        self::assertCount(1, $page->filter('.tm-notif__list li'));
        self::assertSame('Devis', $page->filter('.tm-notif__filters a[aria-current="page"]')->text());
        $page = $this->client->request('GET', '/espace-client/notifications?type=compte');
        self::assertSame('Votre mot de passe a été modifié', $page->filter('.tm-notif__body')->text());

        // Ouverture : lue, puis lien interne (chemin seul) ; un lien étranger n'est jamais suivi.
        $this->client->request('GET', '/espace-client/notifications/'.self::N_QUOTE);
        self::assertResponseRedirects('/espace-client/devis?x=1#d');
        $this->client->request('GET', '/espace-client/notifications/'.self::N_MESSAGE);
        self::assertResponseRedirects('/espace-client/notifications');
        self::assertSame(2, $this->unread(self::CLIENT));
        // Une notification d'un autre compte n'est ni lue ni suivie.
        $this->client->request('GET', '/espace-client/notifications/'.self::N_OTHER);
        self::assertResponseRedirects('/espace-client/notifications');
        self::assertSame(1, $this->unread(self::DOSSIER_USER));

        $page = $this->client->request('GET', '/espace-client/notifications');
        $this->client->submit($page->selectButton('Tout marquer comme lu')->form());
        self::assertResponseRedirects('/espace-client/notifications');
        self::assertSame(0, $this->unread(self::CLIENT));
        self::assertSame(1, $this->unread(self::DOSSIER_USER));
        $this->client->request('POST', '/espace-client/notifications/tout-lu', ['_token' => 'faux']);
        self::assertResponseStatusCodeSame(403);

        $commands = self::getContainer()->get(CommandBus::class);
        \assert($commands instanceof CommandBus);
        $commands->dispatch(new PurgeNotifications());
        self::assertSame(0, DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM notification.in_app WHERE id = :id', ['id' => self::N_OLD])));
        self::assertSame(4, DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM notification.in_app')));
    }

    public function testArtisanSpace(): void
    {
        $this->prepareReferentials();
        $this->submittedDossier();
        $this->connection->executeStatement("UPDATE identity.user_account SET status = 'active', email_verified_at = date_trunc('second', now()) WHERE id = :a", ['a' => self::DOSSIER_USER]);
        $this->inbox()->add(self::N_PRO, self::DOSSIER_USER, 'E-30', 'Nouvelle demande près de chez vous (fictive)', '/espace-pro/demandes', new \DateTimeImmutable('-1 day -1 hour'));
        $this->login('dominique.verif@exemple.test');

        $page = $this->client->request('GET', '/espace-pro/notifications');
        self::assertResponseIsSuccessful();
        self::assertSame('Hier', $page->filter('.tm-notif__list time')->text());
        self::assertSame('Notifications', $page->filter('.tm-space__banner-title')->text());
        $this->client->request('GET', '/espace-pro/notifications/'.self::N_PRO);
        self::assertResponseRedirects('/espace-pro/demandes');
        self::assertSame(0, $this->unread(self::DOSSIER_USER));
        $this->client->request('GET', '/espace-client/notifications');
        self::assertResponseStatusCodeSame(403);
    }
}
