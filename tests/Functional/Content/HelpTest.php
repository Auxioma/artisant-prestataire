<?php

declare(strict_types=1);

namespace App\Tests\Functional\Content;

use App\Content\Application\Faq\ImportFaq;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** P14 : rubriques, une question = une URL, recherche et suggestions, vote unique, contact pré-rempli ; P13 ; P12.5. */
final class HelpTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $bus = self::getContainer()->get(CommandBus::class);
        \assert($bus instanceof CommandBus);
        $bus->dispatch(new ImportFaq([
            ['slug' => 'paiement-abonnement', 'title' => 'Paiement & abonnement', 'description' => 'Crédits et forfaits.', 'icon' => 'tag', 'questions' => [
                ['slug' => 'que-consomme-un-credit', 'audience' => 'artisan', 'question' => 'Que consomme un crédit ?', 'answer' => '<p>Seul l’envoi d’un devis consomme un crédit.</p><script>alert(1)</script>'],
                ['slug' => 'devis-offerts', 'audience' => 'artisan', 'question' => 'Combien de devis sont offerts ?', 'answer' => '<p>{free_credits} devis offerts.</p>'],
            ]],
            ['slug' => 'particuliers', 'title' => 'Particuliers', 'description' => 'Trouver un professionnel.', 'icon' => 'users', 'questions' => [
                ['slug' => 'combien-de-devis', 'audience' => 'client', 'question' => 'Combien de devis puis-je recevoir ?', 'answer' => '<p>Jusqu’à {max_devis} devis.</p>'],
            ]],
        ]));
    }

    public function testTopicsQuestionsAndSearch(): void
    {
        $crawler = $this->client->request('GET', '/aide');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('.tm-help__topic'));
        self::assertStringContainsString('2 questions', $crawler->filter('.tm-help__topic')->first()->text());

        $crawler = $this->client->request('GET', '/aide/paiement-abonnement');
        self::assertSelectorTextContains('h1', 'Paiement & abonnement');
        self::assertStringContainsString('100 devis offerts', $crawler->filter('.tm-help__accordion')->text(), '{free_credits} lu dans les paramètres');
        self::assertStringNotContainsString('<script>alert', (string) $this->client->getResponse()->getContent(), 'réponse assainie');
        self::assertStringContainsString('FAQPage', $crawler->filter('script[type="application/ld+json"]')->last()->text());

        $crawler = $this->client->request('GET', '/aide/particuliers/combien-de-devis');
        self::assertSelectorTextContains('h1', 'Combien de devis puis-je recevoir ?');
        self::assertStringContainsString('Jusqu’à 3 devis', $crawler->filter('.tm-help__answer')->text());
        self::assertSame('http://localhost/aide/particuliers/combien-de-devis', $crawler->filter('link[rel="canonical"]')->attr('href'));
        $this->client->request('GET', '/aide/particuliers/inconnue');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/aide?q=credit');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, follow');
        self::assertSelectorTextContains('#results-title', '1 résultat pour « credit »');
        $this->client->request('GET', '/api/aide/suggestions?q=devis');
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertIsArray($data['suggestions']);
        self::assertCount(3, $data['suggestions']);
    }

    public function testOneVotePerVisitorAndContactOnNegativeVote(): void
    {
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $this->client->request('POST', '/aide/particuliers/combien-de-devis/vote', ['_token' => 'csrf-token', 'helpful' => '0'], server: ['HTTP_ACCEPT' => 'application/json']);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertIsString($data['contact']);
        self::assertStringStartsWith('/contact?message=', $data['contact']);
        $this->client->request('POST', '/aide/particuliers/combien-de-devis/vote', ['_token' => 'csrf-token', 'helpful' => '0']);
        self::assertResponseRedirects('/aide/particuliers/combien-de-devis#avis', 303);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Posez-nous votre question', $crawler->filter('#avis')->text());

        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        self::assertSame(1, DbalValue::int($connection->fetchOne("SELECT helpful_no FROM content.faq_question WHERE slug = 'combien-de-devis'")), 'un seul vote par visiteur');
    }

    public function testPricingAndTargetedFaq(): void
    {
        $crawler = $this->client->request('GET', '/tarifs-professionnels');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.tm-pricing__offer', '100 devis offerts');
        self::assertStringNotContainsString('€', $crawler->filter('.tm-pricing__plans')->text(), 'aucun prix d’exemple (Q5)');
        self::assertCount(2, $crawler->filter('.tm-pricing .tm-help__item'));

        $crawler = $this->client->request('GET', '/comment-ca-marche');
        self::assertStringContainsString('Combien de devis puis-je recevoir ?', $crawler->filter('#particuliers')->text());
        self::assertStringContainsString('Que consomme un crédit ?', $crawler->filter('#professionnels')->text());
    }
}
