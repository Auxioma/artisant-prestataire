<?php

declare(strict_types=1);

namespace App\Tests\Functional\Administration;

use App\Identity\Application\Legal\PublishLegalDocument;
use App\Identity\Application\Registration\RegisterClient;
use App\Shared\Application\Bus\Command;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\AdminSession;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

/** BO02 / BO03.1 / BO03.3 : listes filtrées et paginées, fiches, suspension motivée avec E-26, droits par rôle. */
final class UserAdministrationTest extends WebTestCase
{
    use AdminSession;
    use ArtisanDossier;

    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000e0001';

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $this->connection = $connection;
        $this->prepareReferentials();
        $this->dispatch(new PublishLegalDocument('0190f5e4-0000-7000-8000-0000000d0020', 'cgv', 'CGV', '<p>CGV fictives</p>', false));
        $legal = array_map(DbalValue::string(...), $this->connection->fetchFirstColumn("SELECT id FROM identity.legal_document WHERE type IN ('cgu', 'privacy')"));
        $this->dispatch(new RegisterClient(self::CLIENT, 'camille.client@exemple.test', 'cheval agrafe batterie', 'Camille', 'Renaud', '06 12 34 56 78', '76600', 'particulier', null, null, $legal, false));
        $this->dispatch(new RegisterClient('0190f5e4-0000-7000-8000-0000000e0002', 'sci.docks@exemple.test', 'cheval agrafe batterie', 'Julien', 'Moreau', null, '76600', 'professionnel', 'SCI Les Docks', null, $legal, false));
        $this->submittedDossier();
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    private function dispatch(Command $command): void
    {
        $bus = self::getContainer()->get(CommandBus::class);
        \assert($bus instanceof CommandBus);
        $bus->dispatch($command);
    }

    public function testClientListSearchFiltersAndRecord(): void
    {
        $this->adminSession($this->client, '0190f5e4-0000-7000-8000-0000000a0001', 'support');

        $crawler = $this->client->request('GET', '/admin/clients');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('.tm-admin-table tbody tr'), 'les artisans ne sont pas listés');
        $crawler = $this->client->request('GET', '/admin/clients?q=0612');
        self::assertSame(['Camille Renaud'], $crawler->filter('.tm-admin-table tbody th')->each(static fn ($n): string => $n->text()));
        $crawler = $this->client->request('GET', '/admin/clients?type=professionnel');
        self::assertSame(['Julien Moreau'], $crawler->filter('.tm-admin-table tbody th')->each(static fn ($n): string => $n->text()));

        $crawler = $this->client->request('GET', '/admin/clients?client='.self::CLIENT);
        $record = $crawler->filter('.tm-users__record')->text();
        self::assertStringContainsString('06 •• •• •• 78', $record, 'téléphone masqué');
        self::assertStringNotContainsString('12 34 56', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('CGU v', $record);
        self::assertStringContainsString('E-mail non vérifié', $record);
    }

    public function testClientSuspensionIsReasonedLoggedAndNotified(): void
    {
        $this->adminSession($this->client, '0190f5e4-0000-7000-8000-0000000a0001', 'support');
        $crawler = $this->client->request('GET', '/admin/clients?client='.self::CLIENT);
        $token = (string) $crawler->filter('form.tm-users__actions input[name="_token"]')->attr('value');

        $this->client->request('POST', '/admin/clients/'.self::CLIENT.'/action', ['_token' => $token, 'action' => 'suspend', 'reason' => '']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.tm-toasts', 'Motif de suspension obligatoire');

        $this->client->request('POST', '/admin/clients/'.self::CLIENT.'/action', ['_token' => $token, 'action' => 'suspend', 'reason' => 'Signalements répétés']);
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertSame('E-26', $mail->getHeaders()->getHeaderBody('X-TrouveMoi-Template'));
        self::assertStringContainsString('Signalements répétés', (string) $mail->getTextBody());
        self::assertSame('suspended', DbalValue::string($this->connection->fetchOne('SELECT status FROM identity.user_account WHERE id = :id', ['id' => self::CLIENT])));
        self::assertSame('admin', DbalValue::string($this->connection->fetchOne("SELECT actor_type FROM admin.audit_log WHERE action = 'identity.user_suspended'")));

        $this->client->request('POST', '/admin/clients/'.self::CLIENT.'/action', ['_token' => $token, 'action' => 'reinstate']);
        self::assertSame('pending_email', DbalValue::string($this->connection->fetchOne('SELECT status FROM identity.user_account WHERE id = :id', ['id' => self::CLIENT])));
    }

    public function testArtisanListRecordAndSuspension(): void
    {
        $this->adminSession($this->client, '0190f5e4-0000-7000-8000-0000000a0001', 'moderator');
        $profile = DbalValue::string($this->connection->fetchOne('SELECT id FROM artisan.artisan_profile'));

        $crawler = $this->client->request('GET', '/admin/artisans');
        self::assertResponseIsSuccessful();
        $row = $crawler->filter('.tm-admin-table tbody tr')->text();
        self::assertStringContainsString('Atelier Fictif', $row);
        self::assertStringContainsString('90000000100017', $row);
        self::assertStringContainsString('Plombier', $row);
        self::assertStringContainsString('À vérifier', $row);
        $crawler = $this->client->request('GET', '/admin/artisans?q=900000001');
        self::assertCount(1, $crawler->filter('.tm-admin-table tbody th'));
        $crawler = $this->client->request('GET', '/admin/artisans?statut=verified');
        self::assertStringContainsString('Aucun artisan', $crawler->filter('.tm-admin-table tbody')->text());

        $crawler = $this->client->request('GET', '/admin/artisans/'.$profile);
        self::assertSelectorTextContains('h1', 'Atelier Fictif');
        $token = (string) $crawler->filter('form.tm-verif__decision input[name="_token"]')->attr('value');
        $this->client->request('POST', '/admin/artisans/'.$profile.'/statut', ['_token' => $token, 'action' => 'suspend', 'reason' => 'Doublon de compte suspecté']);
        self::assertSame('suspended|Doublon de compte suspecté', DbalValue::string($this->connection->fetchOne("SELECT status || '|' || status_comment FROM artisan.artisan_profile")));
        $crawler = $this->client->request('GET', '/admin/artisans/'.$profile);
        self::assertStringContainsString('Motif : Doublon de compte suspecté', $crawler->filter('.tm-verif__head')->text());
    }

    public function testListsFollowPermissions(): void
    {
        $this->adminSession($this->client, '0190f5e4-0000-7000-8000-0000000a0001', 'content');
        $this->client->request('GET', '/admin/clients');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/admin/artisans');
        self::assertResponseStatusCodeSame(403);

        $this->client->getCookieJar()->clear();
        $this->adminSession($this->client, '0190f5e4-0000-7000-8000-0000000a0002', 'read_only');
        $crawler = $this->client->request('GET', '/admin/clients?client='.self::CLIENT);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form.tm-users__actions'), 'lecture seule : aucune action');
    }
}
