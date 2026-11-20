<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Application\Command\RegisterUser;
use App\Identity\Application\Privacy\BuildDataExport;
use App\Identity\Application\Privacy\ExpireDataExports;
use App\Identity\Domain\Privacy\DataExportRequested;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** T-705d1 — C07.4 « Télécharger mes données » : demande, génération (JSON + PDF), E-07, téléchargement, expiration. */
final class ClientDataExportTest extends WebTestCase
{
    use ArtisanDossier;

    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000f7901';

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
        $this->dossierDispatch(new RegisterUser(self::CLIENT, 'camille.c074@exemple.test', 'cheval agrafe batterie', 'client', 'Camille', 'Fictive'));
        $connection->executeStatement("UPDATE identity.user_account SET status = 'active', email_verified_at = date_trunc('second', now()) WHERE id = :c", ['c' => self::CLIENT]);
        $connection->executeStatement("INSERT INTO identity.client_profile (user_id, type, postal_code, interests, created_at, updated_at) VALUES (:c, 'particulier', '76600', '[\"jardin\"]', date_trunc('second', now()), date_trunc('second', now()))", ['c' => self::CLIENT]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $crawler = $this->client->request('GET', '/connexion');
        $this->client->request('POST', '/connexion', ['_username' => 'camille.c074@exemple.test', '_password' => 'cheval agrafe batterie', '_csrf_token' => (string) $crawler->filter('input[name="_csrf_token"]')->attr('value')]);
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    public function testRequestBuildDownloadAndExpire(): void
    {
        $page = $this->client->request('GET', '/espace-client/donnees');
        self::assertResponseIsSuccessful();
        $this->client->submit($page->selectButton('Demander une nouvelle archive')->form());
        $page = $this->client->followRedirect();
        self::assertStringContainsString('en préparation', $page->filter('.tm-account__card')->text());
        self::assertCount(0, $page->filter('button:contains("Demander une nouvelle archive")'), 'une demande à la fois');
        $this->client->request('GET', '/espace-client/donnees/export/telecharger');
        self::assertResponseStatusCodeSame(404);

        // Génération (worker).
        $id = DbalValue::string($this->connection->fetchOne('SELECT id FROM identity.data_export WHERE user_id = :u', ['u' => self::CLIENT]));
        $build = self::getContainer()->get(BuildDataExport::class);
        \assert($build instanceof BuildDataExport);
        $build(new DataExportRequested($id, self::CLIENT, new \DateTimeImmutable()));
        self::assertSame('ready', DbalValue::string($this->connection->fetchOne('SELECT status FROM identity.data_export WHERE id = :id', ['id' => $id])));
        self::assertSame('sent', DbalValue::string($this->connection->fetchOne("SELECT status FROM notification.delivery WHERE code = 'E-07' AND channel = 'email'")));
        self::assertNotNull($this->connection->fetchOne("SELECT wrapped_dek FROM shared.stored_file WHERE purpose = 'data_export'"), 'archive chiffrée au repos');

        $page = $this->client->request('GET', '/espace-client/donnees');
        self::assertStringContainsString('Votre archive est prête', $page->filter('.tm-account__card')->text());
        $this->client->request('GET', '/espace-client/donnees/export/telecharger');
        self::assertResponseIsSuccessful();
        $zipPath = tempnam(sys_get_temp_dir(), 'tm-test-');
        \assert(\is_string($zipPath));
        file_put_contents($zipPath, $this->client->getInternalResponse()->getContent());
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($zipPath));
        $json = json_decode((string) $zip->getFromName('donnees.json'), true);
        self::assertIsArray($json);
        self::assertSame('Camille Fictive', $json['titulaire']);
        $account = self::row($json, 'compte');
        self::assertSame('camille.c074@exemple.test', $account['e_mail']);
        self::assertSame(['jardin'], $account['domaines_d_interet']);
        self::assertArrayNotHasKey('password_hash', $account);
        self::assertStringStartsWith('%PDF', (string) $zip->getFromName('donnees.pdf'));
        $zip->close();
        unlink($zipPath);

        // Nouvelle demande refusée dans les 24 h ; lien expiré : archive détruite.
        $this->client->submit($page->selectButton('Demander une nouvelle archive')->form());
        $this->client->followRedirect();
        self::assertSame(1, DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM identity.data_export WHERE user_id = :u', ['u' => self::CLIENT])));
        $this->connection->executeStatement("UPDATE identity.data_export SET expires_at = now() - interval '1 minute' WHERE id = :id", ['id' => $id]);
        $commands = self::getContainer()->get(CommandBus::class);
        \assert($commands instanceof CommandBus);
        $commands->dispatch(new ExpireDataExports());
        self::assertSame('expired', DbalValue::string($this->connection->fetchOne('SELECT status FROM identity.data_export WHERE id = :id', ['id' => $id])));
        self::assertSame('shredded', DbalValue::string($this->connection->fetchOne("SELECT status FROM shared.stored_file WHERE purpose = 'data_export'")));
        $this->client->request('GET', '/espace-client/donnees/export/telecharger');
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @param array<mixed> $json
     *
     * @return array<mixed>
     */
    private static function row(array $json, string $section): array
    {
        $sections = $json['sections'] ?? null;
        self::assertIsArray($sections);
        $rows = $sections[$section] ?? null;
        self::assertIsArray($rows);
        $row = $rows[0] ?? null;
        self::assertIsArray($row);

        return $row;
    }
}
