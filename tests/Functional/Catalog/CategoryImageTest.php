<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

use App\Catalog\Application\Command\CreateCategory;
use App\Catalog\Application\Command\CreateJob;
use App\Content\Application\Media\DeleteMedia;
use App\Content\Application\Media\MediaRejected;
use App\Shared\Application\Bus\CommandBus;
use App\Tests\Support\AdminSession;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/** BO06 — image de la carte catégorie choisie dans la médiathèque : accueil, retrait, image protégée contre la suppression. */
final class CategoryImageTest extends WebTestCase
{
    use AdminSession;

    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000b7001';
    private const string FILE = '0190f5e4-0000-7000-8000-0000000b7010';
    private const string MEDIA = '0190f5e4-0000-7000-8000-0000000b7011';

    private KernelBrowser $client;
    private Connection $connection;
    private CommandBus $commands;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        $commands = self::getContainer()->get(CommandBus::class);
        \assert($connection instanceof Connection && $commands instanceof CommandBus);
        $this->connection = $connection;
        $this->commands = $commands;
        $this->commands->dispatch(new CreateCategory('Piscine fictive', 'wrench', featured: true));
        $this->commands->dispatch(new CreateJob('piscine-fictive', 'Pisciniste fictif'));
        $this->connection->executeStatement("INSERT INTO shared.stored_file (id, purpose, status, mime, size, sha256, created_at) VALUES (:f, 'media', 'available', 'image/webp', 10, repeat('a', 64), now())", ['f' => self::FILE]);
        $this->connection->executeStatement("INSERT INTO content.media (id, file_id, title, alt, status, width, height, variants, created_at, updated_at) VALUES (:m, :f, 'Bassin (fictif)', 'Un bassin', 'ready', 640, 360, :v, now(), now())", ['m' => self::MEDIA, 'f' => self::FILE, 'v' => json_encode(['webp' => ['640' => self::FILE]], \JSON_THROW_ON_ERROR)]);
        $this->adminSession($this->client, self::ADMIN, 'content');
    }

    public function testImageChosenInTheBackOfficeIllustratesTheHomeCard(): void
    {
        $crawler = $this->client->request('GET', '/admin/referentiel');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#categorie-piscine-fictive summary', 'sans image');
        self::assertSelectorTextContains('#cat_piscine-fictive_image', 'Bassin (fictif)');
        $form = $crawler->filter('#categorie-piscine-fictive')->selectButton('Enregistrer la catégorie')->form();
        $this->client->submit($form, ['image_media_id' => self::MEDIA]);
        self::assertResponseRedirects();
        self::assertSame(self::MEDIA, $this->connection->fetchOne("SELECT image_media_id FROM catalog.category WHERE slug = 'piscine-fictive'"));

        $home = $this->client->request('GET', '/');
        $card = $home->filter('.tm-home-categories__card[href$="piscine-fictive"]');
        self::assertCount(1, $card, 'carte de la catégorie à la une');
        self::assertStringContainsString('/medias/'.self::MEDIA.'/640.webp', (string) $card->filter('img')->attr('src'));
        self::assertStringNotContainsString('tm-home-categories__card--plain', (string) $card->attr('class'));

        try {
            $this->commands->dispatch(new DeleteMedia(self::MEDIA, self::ADMIN));
            self::fail('image utilisée par une catégorie : suppression refusée');
        } catch (MediaRejected|HandlerFailedException $e) {
            self::assertStringContainsString('Catégorie « Piscine fictive »', $e->getMessage());
        }

        $crawler = $this->client->request('GET', '/admin/referentiel');
        $this->client->submit($crawler->filter('#categorie-piscine-fictive')->selectButton('Enregistrer la catégorie')->form(), ['image_media_id' => '']);
        self::assertNull($this->connection->fetchOne("SELECT image_media_id FROM catalog.category WHERE slug = 'piscine-fictive'"));
        $card = $this->client->request('GET', '/')->filter('.tm-home-categories__card[href$="piscine-fictive"]');
        self::assertStringContainsString('tm-home-categories__card--plain', (string) $card->attr('class'), 'sans image : fond uni et pictogramme');
    }
}
