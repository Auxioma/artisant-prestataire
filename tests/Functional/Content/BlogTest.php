<?php

declare(strict_types=1);

namespace App\Tests\Functional\Content;

use App\Catalog\Application\Command\CreateCategory;
use App\Catalog\Application\Command\CreateJob;
use App\Content\Application\Media\DeleteMedia;
use App\Content\Application\Media\MediaRejected;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\AdminSession;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/** P15 / BO10.3 — blog : catégories, article (contenu assaini, métiers liés), aperçu, publication, liste, filtres, sitemap, retrait. */
final class BlogTest extends WebTestCase
{
    use AdminSession;

    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000b1001';

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

    private function articleId(): string
    {
        return DbalValue::string($this->connection->fetchOne('SELECT id FROM content.blog_article'));
    }

    /** @param array<string, mixed> $fields */
    private function act(string $action, array $fields = []): void
    {
        $page = $this->client->request('GET', '/admin/cms/blog/'.$this->articleId());
        $token = (string) $page->filter('input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/admin/cms/blog/'.$this->articleId(), ['_token' => $token, 'action' => $action] + $fields);
    }

    public function testWritePublishListAndUnpublish(): void
    {
        $list = $this->client->request('GET', '/admin/cms/blog');
        self::assertResponseIsSuccessful();
        $this->client->submit($list->selectButton('Ajouter la catégorie')->form(), ['data[name]' => 'Plomberie (fictif)']);
        self::assertResponseRedirects();
        $category = DbalValue::string($this->connection->fetchOne('SELECT id FROM content.blog_category'));
        self::assertSame('plomberie-fictif', DbalValue::string($this->connection->fetchOne('SELECT slug FROM content.blog_category')));

        $list = $this->client->request('GET', '/admin/cms/blog');
        $this->client->submit($list->selectButton('Créer l’article')->form(), ['data[title]' => 'Fuite d’eau : les bons réflexes (fictif)']);
        self::assertResponseRedirects('/admin/cms/blog/'.$this->articleId());
        $this->client->request('GET', '/blog/fuite-d-eau-les-bons-reflexes-fictif');
        self::assertResponseStatusCodeSame(404, 'brouillon jamais publié');

        $this->act('publish');
        self::assertNull($this->connection->fetchOne('SELECT published_at FROM content.blog_article'), 'publication refusée sans texte');

        $commands = self::getContainer()->get(CommandBus::class);
        \assert($commands instanceof CommandBus);
        $commands->dispatch(new CreateCategory('Plomberie', 'wrench'));
        $commands->dispatch(new CreateJob('plomberie', 'Plombier'));
        $plumber = DbalValue::string($this->connection->fetchOne("SELECT id FROM catalog.job WHERE slug = 'plombier'"));
        $this->act('save', [
            'data' => ['title' => 'Fuite d’eau : les bons réflexes (fictif)', 'short_title' => 'Fuite d’eau', 'excerpt' => 'Les gestes à connaître.', 'category_id' => $category,
                'content_html' => '<h2>Couper l’eau</h2><p>Fermez le robinet.</p><script>alert(1)</script><h2>Localiser la fuite</h2><p>Observez le compteur.</p>'],
            'job_ids' => [$plumber, '0190f5e4-0000-7000-8000-00000000dead'],
            'featured' => '1',
        ]);
        self::assertSame('{'.$plumber.'}', DbalValue::string($this->connection->fetchOne('SELECT job_ids::text FROM content.blog_article')), 'métier inconnu ignoré');
        self::assertStringNotContainsString('script', DbalValue::string($this->connection->fetchOne('SELECT content_html FROM content.blog_article')));

        $preview = $this->client->request('GET', '/admin/cms/blog/'.$this->articleId().'/apercu');
        self::assertResponseIsSuccessful();
        self::assertSame('noindex, follow', $preview->filter('meta[name="robots"]')->attr('content'));

        $this->act('publish');
        $article = $this->client->request('GET', '/blog/fuite-d-eau-les-bons-reflexes-fictif');
        self::assertResponseIsSuccessful();
        self::assertSame('Fuite d’eau : les bons réflexes (fictif)', $article->filter('h1')->text());
        self::assertCount(2, $article->filter('.tm-blog-article__toc li'), 'sommaire : un lien par intertitre');
        self::assertStringContainsString('Trouvez un plombier', $article->filter('.tm-blog-cta')->text(), 'encart avant le 2e intertitre');
        self::assertCount(1, $article->filter('.tm-blog-article__content h2:nth-of-type(2) ~ aside, .tm-blog-cta + h2'));
        self::assertCount(0, $article->filter('script:contains("alert")'));
        self::assertCount(1, $article->filter('script[type="application/ld+json"]:contains("BlogPosting"), script[type="application/ld+json"]:contains("Article")'));

        $blog = $this->client->request('GET', '/blog');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Fuite d’eau', $blog->filter('.tm-blog-featured')->text());
        self::assertCount(1, $blog->filter('.tm-blog__filters a:contains("Plomberie (fictif)")'));
        $this->client->request('GET', '/blog/categorie/plomberie-fictif');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/blog?page=2');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/sitemaps/blog-1.xml');
        self::assertStringContainsString('/blog/fuite-d-eau-les-bons-reflexes-fictif', (string) $this->client->getResponse()->getContent());

        $this->act('unpublish');
        $this->client->request('GET', '/blog/fuite-d-eau-les-bons-reflexes-fictif');
        self::assertResponseStatusCodeSame(404);
        self::assertCount(0, $this->client->request('GET', '/blog')->filter('.tm-blog-featured'));
    }

    public function testMediaUsedByAnArticleCannotBeDeleted(): void
    {
        $file = '0190f5e4-0000-7000-8000-0000000b1010';
        $media = '0190f5e4-0000-7000-8000-0000000b1011';
        $this->connection->executeStatement("INSERT INTO shared.stored_file (id, purpose, status, mime, size, sha256, created_at) VALUES (:f, 'media', 'available', 'image/png', 10, repeat('a', 64), now())", ['f' => $file]);
        $this->connection->executeStatement("INSERT INTO content.media (id, file_id, title, alt, status, created_at, updated_at) VALUES (:m, :f, 'Photo (fictif)', 'Une photo', 'ready', now(), now())", ['m' => $media, 'f' => $file]);
        $this->connection->executeStatement("INSERT INTO content.blog_article (id, slug, title, cover_media_id, created_at, updated_at) VALUES ('0190f5e4-0000-7000-8000-0000000b1012', 'guide', 'Guide du blog (fictif)', :m, now(), now())", ['m' => $media]);
        $commands = self::getContainer()->get(CommandBus::class);
        \assert($commands instanceof CommandBus);
        try {
            $commands->dispatch(new DeleteMedia($media, self::ADMIN));
            self::fail('suppression refusée attendue');
        } catch (MediaRejected|HandlerFailedException $e) {
            self::assertStringContainsString('Guide du blog (fictif)', $e->getMessage());
        }
    }
}
