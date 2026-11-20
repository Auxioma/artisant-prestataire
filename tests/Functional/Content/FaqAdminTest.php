<?php

declare(strict_types=1);

namespace App\Tests\Functional\Content;

use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\AdminSession;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** T-801c — BO10.4 FAQ / Aide : rubriques, questions (public, publication, ordre), statistiques de votes, aide publique. */
final class FaqAdminTest extends WebTestCase
{
    use AdminSession;

    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a8c01';

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

    /** @param array<string, mixed> $fields */
    private function post(array $fields): void
    {
        $page = $this->client->request('GET', '/admin/cms/faq');
        $token = (string) $page->filter('form[action="/admin/cms/faq"] input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/admin/cms/faq', ['_token' => $token] + $fields);
    }

    private function topic(string $slug): string
    {
        return DbalValue::string($this->connection->fetchOne('SELECT id FROM content.faq_topic WHERE slug = :s', ['s' => $slug]));
    }

    public function testTopicsQuestionsOrderPublicationAndStats(): void
    {
        $this->client->request('GET', '/admin/cms/faq');
        self::assertResponseIsSuccessful();

        $this->post(['action' => 'topic_save', 'data' => ['title' => 'Paiements (fictif)', 'description' => 'Régler un artisan en toute sécurité.', 'icon' => 'tag']]);
        $topic = $this->topic('paiements-fictif');
        $this->post(['action' => 'question_save', 'topic' => $topic, 'data' => ['question' => 'Comment payer un artisan ?', 'answer' => '<p>Directement à l’artisan.</p><script>x()</script>', 'audience' => 'client']]);
        $this->post(['action' => 'question_save', 'topic' => $topic, 'data' => ['question' => 'Puis-je payer en ligne ?', 'answer' => '<p>Pas encore.</p>', 'audience' => 'all']]);
        self::assertSame('<p>Directement à l’artisan.</p>', DbalValue::string($this->connection->fetchOne("SELECT answer_html FROM content.faq_question WHERE slug = 'comment-payer-un-artisan'")));

        $public = $this->client->request('GET', '/aide/paiements-fictif');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Comment payer un artisan ?', $public->filter('main')->text());

        // Ordre, masquage, statistiques.
        $second = DbalValue::string($this->connection->fetchOne("SELECT id FROM content.faq_question WHERE slug = 'puis-je-payer-en-ligne'"));
        $this->post(['action' => 'question_up', 'id' => $second, 'topic' => $topic]);
        self::assertSame(0, DbalValue::int($this->connection->fetchOne('SELECT position FROM content.faq_question WHERE id = :id', ['id' => $second])));
        $this->post(['action' => 'question_save', 'id' => $second, 'topic' => $topic, 'data' => ['question' => 'Puis-je payer en ligne ?', 'answer' => '<p>Pas encore.</p>', 'audience' => 'all', 'slug' => 'puis-je-payer-en-ligne', 'topic' => $topic]]);
        self::assertStringNotContainsString('Puis-je payer en ligne ?', $this->client->request('GET', '/aide/paiements-fictif')->filter('main')->text(), 'question décochée « Publiée » : masquée');
        $this->connection->executeStatement("UPDATE content.faq_question SET helpful_yes = 3, helpful_no = 1 WHERE slug = 'comment-payer-un-artisan'");
        $page = $this->client->request('GET', '/admin/cms/faq?rubrique='.$topic);
        self::assertStringContainsString('75 % utile (4 votes)', $page->filter('main')->text());

        // Une rubrique non vide ne se supprime pas.
        $this->post(['action' => 'topic_delete', 'id' => $topic]);
        self::assertSame(1, DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM content.faq_topic WHERE id = :id', ['id' => $topic])));
    }
}
