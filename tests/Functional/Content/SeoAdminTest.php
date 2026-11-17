<?php

declare(strict_types=1);

namespace App\Tests\Functional\Content;

use App\Administration\Infrastructure\Settings\DbalSettingsProvider;
use App\Catalog\Application\Command\CreateCategory;
use App\Catalog\Application\Command\CreateJob;
use App\Shared\Application\Bus\Command;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\AdminSession;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\Cache\CacheInterface;

/** BO12 : gabarits et surcharges (BO12.1), redirections + CSV et rapport des 404 (BO12.2), robots et données structurées. */
final class SeoAdminTest extends WebTestCase
{
    use AdminSession;

    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a0007';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->dispatch(new CreateCategory('Plomberie', 'wrench'));
        $this->dispatch(new CreateJob('plomberie', 'Plombier'));
    }

    public function testTemplatesAndOverridesDriveTheMeta(): void
    {
        $this->adminSession($this->client, self::ADMIN, 'content');
        $this->post('gabarits', '/admin/seo/gabarits/job', ['title' => '{metier} vérifié près de chez vous {ville} | TrouveMoi', 'description' => 'Comparez les {metier} vérifiés.']);
        $crawler = $this->client->request('GET', '/metiers/plombier');
        self::assertSame('Plombier vérifié près de chez vous | TrouveMoi', $crawler->filter('title')->text(), 'variable sans valeur retirée');
        self::assertSame('Comparez les Plombier vérifiés.', $crawler->filter('meta[name="description"]')->attr('content'));

        $this->post('surcharges', '/admin/seo/surcharges', ['path' => '/metiers/plombier', 'title' => 'Plombiers & dépanneurs', 'description' => '', 'noindex' => '1']);
        $crawler = $this->client->request('GET', '/metiers/plombier');
        self::assertSame('Plombiers & dépanneurs', $crawler->filter('title')->text());
        self::assertSame('Plombiers & dépanneurs', $crawler->filter('meta[property="og:title"]')->attr('content'), 'échappé une seule fois');
        self::assertCount(1, $crawler->filter('meta[name="robots"][content="noindex, follow"]'));
        self::assertSame('Comparez les Plombier vérifiés.', $crawler->filter('meta[name="description"]')->attr('content'), 'description vide : gabarit conservé');

        $this->post('surcharges', '/admin/seo/surcharges', ['path' => 'https://ailleurs.example/x']);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('chemin interne attendu', $crawler->filter('.tm-toasts')->text());
    }

    public function testRedirectsImportAndNotFoundReport(): void
    {
        $this->client->request('GET', '/metiers/plombiers');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/metiers/plombiers');

        $this->adminSession($this->client, self::ADMIN, 'content');
        $crawler = $this->client->request('GET', '/admin/seo?onglet=404');
        $row = $crawler->filter('.tm-admin-table tbody tr')->first()->text();
        self::assertStringContainsString('/metiers/plombiers', $row);
        self::assertStringContainsString('2', $row);
        self::assertStringContainsString('/metiers/plombier', $crawler->filter('.tm-admin-table tbody td a')->first()->text(), 'suggestion de redirection');

        $this->post('redirections', '/admin/seo/redirections', ['source' => '/metiers/plombiers', 'target' => '/metiers/plombier', 'status' => '301', 'action' => 'save']);
        $this->client->request('GET', '/metiers/plombiers');
        self::assertResponseRedirects('/metiers/plombier', 301);
        $crawler = $this->client->request('GET', '/admin/seo?onglet=404');
        self::assertStringContainsString('Aucune erreur 404', $crawler->filter('.tm-admin-panel')->text(), 'adresse redirigée retirée du rapport');

        $csv = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($csv, "source;cible;code\n/ancien-plombier;/metiers/plombier;302\n/vieux;/categories\n");
        $crawler = $this->client->request('GET', '/admin/seo?onglet=redirections');
        $token = (string) $crawler->filter('form[enctype="multipart/form-data"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/admin/seo/redirections', ['_token' => $token, 'action' => 'import'], ['csv' => new UploadedFile($csv, 'redirections.csv', 'text/csv', null, true)]);
        $this->client->request('GET', '/ancien-plombier');
        self::assertResponseRedirects('/metiers/plombier', 302);

        file_put_contents($csv, "/ok;/categories\nhttps://evil.example;/x\n");
        $this->client->request('POST', '/admin/seo/redirections', ['_token' => $token, 'action' => 'import'], ['csv' => new UploadedFile($csv, 'redirections.csv', 'text/csv', null, true)]);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Ligne 2', $crawler->filter('.tm-toasts')->text());
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        self::assertSame(0, DbalValue::int($connection->fetchOne("SELECT count(*) FROM content.redirect WHERE source_path = '/ok'")), 'fichier invalide : rien n’est importé');
    }

    public function testRobotsAndSchemasAreEditable(): void
    {
        $this->adminSession($this->client, self::ADMIN, 'content');
        $this->post('robots', '/admin/seo/robots', ['robots_extra' => "Disallow: /test-bo12\n\n", 'schema_organization' => '1', 'schema_faq' => '1']);
        $cache = self::getContainer()->get('cache.settings');
        $settings = self::getContainer()->get(DbalSettingsProvider::class);
        \assert($cache instanceof CacheInterface && $settings instanceof DbalSettingsProvider);
        $cache->delete(DbalSettingsProvider::CACHE_KEY);
        $settings->reset();

        $this->client->request('GET', '/robots.txt');
        self::assertStringContainsString('Disallow: /test-bo12', (string) $this->client->getResponse()->getContent());
        self::assertFalse($settings->bool(SettingKey::SeoSchemaBreadcrumb), 'case décochée');
    }

    public function testSupportRoleCannotAccessSeo(): void
    {
        $this->adminSession($this->client, '0190f5e4-0000-7000-8000-0000000a0008', 'support');
        $this->client->request('GET', '/admin/seo');
        self::assertResponseStatusCodeSame(403);
    }

    /** @param array<string, string> $data */
    private function post(string $tab, string $action, array $data): void
    {
        $crawler = $this->client->request('GET', '/admin/seo?onglet='.$tab);
        $token = (string) $crawler->filter('form[action="'.$action.'"] input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', $action, $data + ['_token' => $token]);
        self::assertResponseRedirects();
    }

    private function dispatch(Command $command): void
    {
        $bus = self::getContainer()->get(CommandBus::class);
        \assert($bus instanceof CommandBus);
        $bus->dispatch($command);
    }
}
