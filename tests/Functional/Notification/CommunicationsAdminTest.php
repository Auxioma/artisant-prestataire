<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notification;

use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\AdminSession;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** T-802b — BO11 : liste filtrée, éditeur, aperçu isolé, brouillon, enregistrement, test, journal et export. */
final class CommunicationsAdminTest extends WebTestCase
{
    use AdminSession;

    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a9101';

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $this->connection = $connection;
        $this->adminSession($this->client, self::ADMIN, 'content');
    }

    public function testEditPreviewSaveTestAndLog(): void
    {
        $page = $this->client->request('GET', '/admin/communications?q=mot+de+passe');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('E-04', $page->filter('.tm-bo11-table')->text());
        $page = $this->client->request('GET', '/admin/communications?modele=E-04');
        self::assertStringContainsString('Éditer : E-04', $page->filter('#editeur')->text());

        $this->client->request('GET', '/admin/communications/modeles/E-04/apercu');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('sandbox', (string) $this->client->getResponse()->headers->get('Content-Security-Policy'));
        self::assertStringContainsString('Camille', (string) $this->client->getResponse()->getContent());

        // Brouillon invalide : refusé, contenu gardé en aperçu.
        $this->client->submit($page->filter('#editeur')->selectButton('Enregistrer')->form(), ['data[subject]' => 'Nouveau {{ prenom ']);
        self::assertResponseRedirects();
        $page = $this->client->followRedirect();
        self::assertStringContainsString('brouillon non enregistré', $page->filter('#editeur')->text());
        $version = DbalValue::int($this->connection->fetchOne("SELECT version FROM notification.template WHERE code = 'E-04'"));

        $this->client->submit($page->filter('#editeur')->selectButton('Enregistrer')->form(), ['data[subject]' => 'Mot de passe changé, {{ prenom }}']);
        self::assertSame($version + 1, DbalValue::int($this->connection->fetchOne("SELECT version FROM notification.template WHERE code = 'E-04'")));

        $page = $this->client->request('GET', '/admin/communications?modele=E-04');
        $this->client->submit($page->filter('#editeur')->selectButton('Envoyer un test')->form());
        $page = $this->client->request('GET', '/admin/communications?journal_modele=E-04');
        self::assertStringContainsString('Envoyé', $page->filter('#journal')->text());
        $this->client->request('GET', '/admin/communications/journal.csv?journal_modele=E-04');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('E-04', (string) $this->client->getInternalResponse()->getContent());
    }
}
