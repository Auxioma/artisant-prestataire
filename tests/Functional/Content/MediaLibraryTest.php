<?php

declare(strict_types=1);

namespace App\Tests\Functional\Content;

use App\Content\Application\Media\GenerateMediaVariants;
use App\Shared\Application\Storage\ScanQuarantinedFile;
use App\Shared\Domain\Storage\FileAvailable;
use App\Shared\Domain\Storage\FileQuarantined;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\AdminSession;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\FileFormField;

/** T-801a — BO10.7 médiathèque : texte alternatif obligatoire, variantes WebP / AVIF, diffusion publique, suppression. */
final class MediaLibraryTest extends WebTestCase
{
    use AdminSession;

    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a8a01';
    private const string SUPPORT = '0190f5e4-0000-7000-8000-0000000a8a02';

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $this->connection = $connection;
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    private function submit(string $alt, string $image): void
    {
        $page = $this->client->request('GET', '/admin/cms/medias');
        $form = $page->selectButton('Déposer')->form();
        $field = $form['file'];
        self::assertInstanceOf(FileFormField::class, $field);
        $field->upload(StorageSamples::file($image));
        $this->client->submit($form, ['alt' => $alt, 'title' => '', 'focal' => 'tc']);
    }

    public function testUploadConvertServeAndDelete(): void
    {
        $this->adminSession($this->client, self::ADMIN, 'content');
        $this->submit('  ', StorageSamples::png(1200, 600));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('texte alternatif est obligatoire', $this->client->getCrawler()->filter('#media_alt_error')->text());

        $this->submit('Artisan posant un carrelage (fictif)', StorageSamples::png(1200, 600));
        self::assertResponseRedirects('/admin/cms/medias');
        $page = $this->client->followRedirect();
        self::assertStringContainsString('Analyse et conversion en cours', $page->filter('.tm-media-grid')->text());

        // Worker : analyse antivirus puis variantes.
        $media = $this->connection->fetchAssociative('SELECT id, file_id FROM content.media');
        \assert(\is_array($media));
        $file = DbalValue::string($media['file_id']);
        $scan = self::getContainer()->get(ScanQuarantinedFile::class);
        $generate = self::getContainer()->get(GenerateMediaVariants::class);
        \assert($scan instanceof ScanQuarantinedFile && $generate instanceof GenerateMediaVariants);
        $scan(new FileQuarantined($file, 'media', new \DateTimeImmutable()));
        $generate(new FileAvailable($file, 'media', new \DateTimeImmutable()));
        $row = $this->connection->fetchAssociative('SELECT status, width, height, variants::text AS variants FROM content.media');
        \assert(\is_array($row));
        self::assertSame(['ready', 1200, 600], [$row['status'], $row['width'], $row['height']]);
        $variants = json_decode(DbalValue::string($row['variants']), true);
        self::assertIsArray($variants);
        $avif = $variants['avif'] ?? null;
        self::assertIsArray($avif);
        self::assertSame(['480', '960'], array_map('strval', array_keys($avif)), 'pas d’agrandissement au-delà de l’original');

        $page = $this->client->request('GET', '/admin/cms/medias');
        $picture = $page->filter('.tm-media-card picture');
        self::assertSame('tm-media tm-focal--tc', $picture->attr('class'));
        self::assertStringContainsString('960w', (string) $picture->filter('source[type="image/avif"]')->attr('srcset'));
        self::assertSame('Artisan posant un carrelage (fictif)', $picture->filter('img')->attr('alt'));

        $id = DbalValue::string($media['id']);
        $this->client->request('GET', '/medias/'.$id.'/960.avif');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/avif');
        $this->client->request('GET', '/medias/'.$id.'/1600.webp');
        self::assertResponseStatusCodeSame(404);

        $this->client->submit($page->filter('.tm-media-card')->selectButton('Supprimer')->form());
        self::assertSame(0, DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM content.media')));
        self::assertSame(5, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM shared.stored_file WHERE purpose = 'media' AND status = 'shredded'")));
        $this->client->request('GET', '/medias/'.$id.'/960.avif');
        self::assertResponseStatusCodeSame(404);
    }

    public function testRoleWithoutCmsRightsIsDenied(): void
    {
        $this->adminSession($this->client, self::SUPPORT, 'support');
        $this->client->request('GET', '/admin/cms/medias');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/cms/medias', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
    }
}
