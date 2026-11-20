<?php

declare(strict_types=1);

namespace App\Tests\Functional\ArtisanProfile;

use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanVerified;
use App\Search\Application\EventHandler\ProjectArtisanForSearch;
use App\Shared\Application\Storage\ScanQuarantinedFile;
use App\Shared\Domain\Storage\FileQuarantined;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** PR08.6 : réalisations, photos publiées après analyse, galerie sur la fiche, suppression = destruction, propriété. */
final class PortfolioTest extends WebTestCase
{
    use ArtisanDossier;

    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a0001';

    private KernelBrowser $client;
    private Connection $connection;
    private string $profileId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $this->connection = $connection;
        $this->prepareReferentials();
        $this->profileId = $this->submittedDossier();
        $this->uploadPiece('kbis');
        $this->uploadPiece('insurance_rc');
        $this->dossierDispatch(new ApproveArtisan($this->profileId, self::ADMIN));
        $project = self::getContainer()->get(ProjectArtisanForSearch::class);
        \assert($project instanceof ProjectArtisanForSearch);
        $project(new ArtisanVerified($this->profileId, self::DOSSIER_USER, self::ADMIN, new \DateTimeImmutable()));
        $connection->executeStatement("UPDATE identity.user_account SET status = 'active', email_verified_at = date_trunc('second', now()) WHERE id = '".self::DOSSIER_USER."'");

        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $crawler = $this->client->request('GET', '/connexion');
        $this->client->request('POST', '/connexion', ['_username' => 'dominique.verif@exemple.test', '_password' => 'cheval agrafe batterie', '_csrf_token' => (string) $crawler->filter('input[name="_csrf_token"]')->attr('value')]);
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    public function testProjectWithPhotosIsPublishedAfterScan(): void
    {
        $crawler = $this->client->request('GET', '/espace-pro/profil');
        $token = $this->token($crawler, '/espace-pro/profil/realisations');
        $this->client->request('POST', '/espace-pro/profil/realisations', ['_token' => $token, 'title' => 'Salle de bains (chantier fictif)', 'description' => 'Douche à l’italienne, contact : chantier@exemple.fr', 'city' => 'Le Havre', 'done_on' => '2026-05-12']);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('ne peut pas contenir une adresse e-mail', $crawler->filter('.tm-toasts')->text(), 'RG-031');

        $this->client->request('POST', '/espace-pro/profil/realisations', ['_token' => $token, 'title' => 'Salle de bains (chantier fictif)', 'description' => 'Douche à l’italienne.', 'city' => 'Le Havre', 'done_on' => '2026-05-12']);
        $crawler = $this->client->followRedirect();
        $upload = $crawler->filter('form[action$="/photos"]');
        self::assertCount(1, $upload);
        foreach (['before', 'after'] as $kind) {
            $this->client->request('POST', (string) $upload->attr('action'), ['_token' => $token, 'kind' => $kind], ['photo' => new UploadedFile(StorageSamples::file(StorageSamples::jpeg(400, 300)), 'chantier.jpg', 'image/jpeg', null, true)]);
            self::assertResponseRedirects();
        }
        $crawler = $this->client->request('GET', '/pro/atelier-fictif-caen-'.$this->profileId);
        self::assertCount(0, $crawler->filter('#realisations'), 'photos en quarantaine : rien de public');

        $this->scanPhotos();
        $crawler = $this->client->request('GET', '/pro/atelier-fictif-caen-'.$this->profileId);
        self::assertSelectorTextContains('#realisations h3', 'Salle de bains (chantier fictif)');
        self::assertSame(['Avant', 'Après'], $crawler->filter('#realisations figcaption')->each(static fn (Crawler $n): string => $n->text()));
        $src = (string) $crawler->filter('#realisations img')->first()->attr('src');
        $this->client->request('GET', $src);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/jpeg');

        $crawler = $this->client->request('GET', '/espace-pro/profil');
        $gauge = $crawler->filter('.tm-pro-profile__gauge')->text();
        self::assertStringContainsString('Profil complété à 38 %', $gauge, 'description et zone (30 %) + 2 photos publiées sur 5 (8 %)');
        self::assertStringContainsString('Publier des photos', $gauge, 'objectif de 5 photos pas encore atteint');
    }

    public function testDeletionShredsFilesAndOwnershipIsChecked(): void
    {
        $crawler = $this->client->request('GET', '/espace-pro/profil');
        $token = $this->token($crawler, '/espace-pro/profil/realisations');
        $this->client->request('POST', '/espace-pro/profil/realisations', ['_token' => $token, 'title' => 'Terrasse (chantier fictif)']);
        $crawler = $this->client->followRedirect();
        $this->client->request('POST', (string) $crawler->filter('form[action$="/photos"]')->attr('action'), ['_token' => $token, 'kind' => 'photo'], ['photo' => new UploadedFile(StorageSamples::file(StorageSamples::png(300, 200)), 'terrasse.png', 'image/png', null, true)]);
        $fileId = DbalValue::string($this->connection->fetchOne('SELECT file_id FROM artisan.portfolio_photo'));

        $this->client->request('POST', '/espace-pro/profil/photos/0190f5e4-0000-7000-8000-00000000dead/supprimer', ['_token' => $token]);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Photo introuvable', $crawler->filter('.tm-toasts')->text());

        $projectId = DbalValue::string($this->connection->fetchOne('SELECT id FROM artisan.portfolio_project'));
        $this->client->request('POST', '/espace-pro/profil/realisations/'.$projectId.'/supprimer', ['_token' => $token]);
        self::assertResponseRedirects();
        self::assertSame(0, DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM artisan.portfolio_project')));
        self::assertSame('shredded', DbalValue::string($this->connection->fetchOne('SELECT status FROM shared.stored_file WHERE id = :id', ['id' => $fileId])), 'fichier détruit');
    }

    private function scanPhotos(): void
    {
        $scan = self::getContainer()->get(ScanQuarantinedFile::class);
        \assert($scan instanceof ScanQuarantinedFile);
        foreach ($this->connection->fetchFirstColumn('SELECT file_id FROM artisan.portfolio_photo') as $fileId) {
            $scan(new FileQuarantined(DbalValue::string($fileId), 'portfolio_photo', new \DateTimeImmutable()));
        }
    }

    private function token(Crawler $crawler, string $action): string
    {
        return (string) $crawler->filter('form[action="'.$action.'"] input[name="_token"]')->attr('value');
    }
}
