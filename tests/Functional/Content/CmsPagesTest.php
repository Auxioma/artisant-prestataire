<?php

declare(strict_types=1);

namespace App\Tests\Functional\Content;

use App\Content\Application\Media\DeleteMedia;
use App\Content\Application\Media\MediaRejected;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\AdminSession;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/** T-801b — BO10.2 pages à blocs : création, blocs validés et assainis, aperçu, publication immédiate / programmée, retrait. */
final class CmsPagesTest extends WebTestCase
{
    use AdminSession;

    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a8b01';

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

    private function pageId(): string
    {
        return DbalValue::string($this->connection->fetchOne('SELECT id FROM content.page'));
    }

    /** @param array<string, mixed> $fields */
    private function act(string $action, array $fields = []): void
    {
        $page = $this->client->request('GET', '/admin/cms/pages/'.$this->pageId());
        $token = (string) $page->filter('input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/admin/cms/pages/'.$this->pageId(), ['_token' => $token, 'action' => $action] + $fields);
    }

    /** @return list<array{id: string, type: string, data: array<string, mixed>}> */
    private function draft(): array
    {
        $draft = json_decode(DbalValue::string($this->connection->fetchOne('SELECT draft::text FROM content.page')), true);
        \assert(\is_array($draft));

        /** @var list<array{id: string, type: string, data: array<string, mixed>}> $draft */
        return $draft;
    }

    public function testComposePublishScheduleAndUnpublish(): void
    {
        $list = $this->client->request('GET', '/admin/cms/pages');
        self::assertResponseIsSuccessful();
        $this->client->submit($list->selectButton('Créer la page')->form(), ['title' => 'Bien choisir son artisan (fictif)']);
        self::assertResponseRedirects();
        self::assertSame('bien-choisir-son-artisan-fictif', DbalValue::string($this->connection->fetchOne('SELECT slug FROM content.page')));
        $this->client->request('GET', '/pages/bien-choisir-son-artisan-fictif');
        self::assertResponseStatusCodeSame(404, 'brouillon jamais publié');

        $this->act('add_block', ['data' => ['type' => 'text']]);
        $this->act('add_block', ['data' => ['type' => 'cta']]);
        [$text, $cta] = $this->draft();
        $this->act('save_block', ['block' => $text['id'], 'data' => ['title' => 'Nos conseils', 'html' => '<p>Comparez <strong>trois devis</strong>.</p><script>alert(1)</script>']]);
        self::assertSame('<p>Comparez <strong>trois devis</strong>.</p>', $this->draft()[0]['data']['html'], 'texte riche assaini');
        $this->act('save_block', ['block' => $cta['id'], 'data' => ['title' => 'Prêt ?', 'button' => 'Demander un devis', 'link' => 'javascript:alert(1)']]);
        self::assertStringContainsString('chemin du site', $this->client->followRedirect()->filter('.tm-toast, [role="alert"], .tm-flash')->text(''));
        self::assertSame('/demande', $this->draft()[1]['data']['link'] ?? null, 'lien refusé : bloc inchangé');
        $this->act('move_up', ['block' => $cta['id']]);
        self::assertSame('cta', $this->draft()[0]['type']);

        $preview = $this->client->request('GET', '/admin/cms/pages/'.$this->pageId().'/apercu');
        self::assertResponseIsSuccessful();
        self::assertSame('noindex, follow', $preview->filter('meta[name="robots"]')->attr('content'));
        self::assertStringContainsString('Nos conseils', $preview->filter('.tm-page')->text());

        $this->act('publish');
        $public = $this->client->request('GET', '/pages/bien-choisir-son-artisan-fictif');
        self::assertResponseIsSuccessful();
        self::assertSame('Bien choisir son artisan (fictif)', $public->filter('h1')->text());
        self::assertCount(0, $public->filter('script:contains("alert")'));
        $this->client->request('GET', '/sitemaps/pages-1.xml');
        self::assertStringContainsString('/pages/bien-choisir-son-artisan-fictif', (string) $this->client->getResponse()->getContent());

        // Modification programmée : la version en ligne reste visible jusqu'à l'heure prévue.
        $this->act('save_block', ['block' => $text['id'], 'data' => ['title' => 'Conseils mis à jour', 'html' => '<p>Nouveau texte.</p>']]);
        $this->act('schedule', ['at' => new \DateTimeImmutable('+2 days', new \DateTimeZone('Europe/Paris'))->format('Y-m-d\TH:i')]);
        self::assertStringContainsString('Nos conseils', $this->client->request('GET', '/pages/bien-choisir-son-artisan-fictif')->filter('.tm-page')->text());
        self::assertStringContainsString('Publication programmée', $this->client->request('GET', '/admin/cms/pages/'.$this->pageId())->filter('main')->text());
        $this->connection->executeStatement("UPDATE content.page SET pending_at = now() - interval '1 minute'");
        self::assertStringContainsString('Conseils mis à jour', $this->client->request('GET', '/pages/bien-choisir-son-artisan-fictif')->filter('.tm-page')->text());

        $this->act('unpublish');
        $this->client->request('GET', '/pages/bien-choisir-son-artisan-fictif');
        self::assertResponseStatusCodeSame(404);
    }

    public function testMediaUsedByAPageCannotBeDeleted(): void
    {
        $file = '0190f5e4-0000-7000-8000-0000000a8b10';
        $media = '0190f5e4-0000-7000-8000-0000000a8b11';
        $this->connection->executeStatement("INSERT INTO shared.stored_file (id, purpose, status, mime, size, sha256, created_at) VALUES (:f, 'media', 'available', 'image/png', 10, repeat('a', 64), now())", ['f' => $file]);
        $this->connection->executeStatement("INSERT INTO content.media (id, file_id, title, alt, status, created_at, updated_at) VALUES (:m, :f, 'Photo (fictif)', 'Une photo', 'ready', now(), now())", ['m' => $media, 'f' => $file]);
        $this->connection->executeStatement("INSERT INTO content.page (id, slug, title, draft, created_at, updated_at) VALUES ('0190f5e4-0000-7000-8000-0000000a8b12', 'guide', 'Guide (fictif)', :d, now(), now())", ['d' => json_encode([['id' => 'b1', 'type' => 'image', 'data' => ['media' => $media]]])]);
        $commands = self::getContainer()->get(CommandBus::class);
        \assert($commands instanceof CommandBus);
        try {
            $commands->dispatch(new DeleteMedia($media, self::ADMIN));
            self::fail('suppression refusée attendue');
        } catch (MediaRejected|HandlerFailedException $e) {
            self::assertStringContainsString('Guide (fictif)', $e->getMessage());
        }
    }
}
