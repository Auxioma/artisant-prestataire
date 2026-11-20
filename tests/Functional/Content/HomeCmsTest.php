<?php

declare(strict_types=1);

namespace App\Tests\Functional\Content;

use App\Tests\Support\AdminSession;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/** P01 et BO10.1 : contenu initial, brouillon invisible du public, aperçu, publication, programmation, historique. */
final class HomeCmsTest extends WebTestCase
{
    use AdminSession;

    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a0006';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
    }

    public function testInitialHomeFollowsTheSpecification(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Trouvez l’artisan de confiance près de chez vous');
        self::assertStringContainsString('jusqu’à 3 devis gratuits', $crawler->filter('.tm-home-cta')->text(), 'RG-014 : {max_devis} lu dans quotes.max_per_request');
        self::assertStringNotContainsString('{max_devis}', (string) $this->client->getResponse()->getContent());
        $quick = $crawler->filter('.tm-home-quick')->text();
        self::assertStringContainsString('Trouver un professionnel', $quick);
        self::assertStringContainsString('Déposer ma demande de devis', $quick, 'P11 livrée : accès rapide affiché');
        self::assertCount(0, $crawler->filter('#home-categories'), 'aucune catégorie « à la une » : bloc masqué (P01.5)');
        self::assertSame('/', parse_url((string) $crawler->filter('link[rel="canonical"]')->attr('href'), \PHP_URL_PATH));
    }

    public function testDraftPreviewPublishAndRestore(): void
    {
        $this->adminSession($this->client, self::ADMIN, 'content');
        $crawler = $this->client->request('GET', '/admin/cms?bloc=hero');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#editor-title', 'Éditer : Hero (recherche)');

        $form = $crawler->filter('form.tm-cms-form');
        $this->client->request('POST', '/admin/cms/accueil/blocs/hero', [
            '_token' => $form->filter('input[name="_token"]')->attr('value'),
            'enabled' => '1',
            'settings' => ['title' => 'Un artisan vérifié en quelques clics', 'subtitle' => 'Jusqu’à {max_devis} devis gratuits.', 'image_alt' => '', 'job_placeholder' => 'Service', 'place_placeholder' => 'Ville', 'button' => 'Chercher', 'veil' => '40'],
        ]);
        self::assertResponseRedirects('/admin/cms?bloc=hero', 303);

        $this->client->request('GET', '/');
        self::assertSelectorTextContains('h1', 'Trouvez l’artisan de confiance', 'brouillon invisible du public');
        $this->client->request('GET', '/admin/cms/accueil/apercu/rendu');
        self::assertSelectorTextContains('h1', 'Un artisan vérifié en quelques clics');
        self::assertSelectorTextContains('.tm-home-hero__subtitle', 'Jusqu’à 3 devis gratuits.');
        self::assertStringContainsString("frame-ancestors 'self'", (string) $this->client->getResponse()->headers->get('Content-Security-Policy'));
        self::assertSame('SAMEORIGIN', $this->client->getResponse()->headers->get('X-Frame-Options'));

        $this->post('/admin/cms/accueil/blocs/reassurance/activation', ['enabled' => '0']);
        $this->post('/admin/cms/accueil/publier', []);
        $crawler = $this->client->request('GET', '/');
        self::assertSelectorTextContains('h1', 'Un artisan vérifié en quelques clics');
        self::assertCount(0, $crawler->filter('.tm-home-reassurance'), 'bloc désactivé');
        self::assertCount(1, $crawler->filter('.tm-home-hero--veil-40'));

        $crawler = $this->client->request('GET', '/admin/cms/accueil/historique');
        self::assertSelectorTextContains('.tm-admin-table', 'Version 1');
        $restore = $crawler->filter('form[action$="/restaurer"]')->first();
        $this->client->request('POST', (string) $restore->attr('action'), ['_token' => $restore->filter('input[name="_token"]')->attr('value')]);
        $crawler = $this->client->request('GET', '/admin/cms');
        self::assertStringContainsString('Brouillon en cours (version 2', $crawler->filter('.tm-cms-status')->text());
    }

    public function testScheduledPublicationAndValidation(): void
    {
        $this->adminSession($this->client, self::ADMIN, 'content');
        $crawler = $this->client->request('GET', '/admin/cms?bloc=cta');
        $form = $crawler->filter('form.tm-cms-form');
        $this->client->request('POST', '/admin/cms/accueil/blocs/cta', ['_token' => $form->filter('input[name="_token"]')->attr('value'), 'enabled' => '1', 'settings' => ['title' => '', 'text' => 'x', 'button' => 'y', 'background' => 'navy']]);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Titre : champ obligatoire.', $crawler->text());

        $this->post('/admin/cms/accueil/blocs/why_us/deplacer', ['offset' => '-1']);
        $this->post('/admin/cms/accueil/publier', ['publish_at' => new \DateTimeImmutable('+2 days', new \DateTimeZone('Europe/Paris'))->format('Y-m-d\TH:i')]);
        $crawler = $this->client->request('GET', '/admin/cms');
        self::assertStringContainsString('programmée le', $crawler->filter('.tm-cms-status')->text());
        $types = $crawler->filter('.tm-cms-block strong')->each(static fn (Crawler $n): string => $n->text());
        self::assertStringContainsString('Pourquoi TrouveMoi', $types[7], 'bloc remonté avant la bannière CTA');

        $crawler = $this->client->request('GET', '/');
        $sections = $crawler->filter('.tm-home > section, .tm-home > nav')->each(static fn (Crawler $n): string => (string) $n->attr('class'));
        self::assertStringContainsString('tm-home-cta', $sections[array_key_last($sections) - 1], 'ordre public inchangé avant la date programmée');
    }

    public function testPermissionsAndNearestCommune(): void
    {
        $this->adminSession($this->client, self::ADMIN, 'support');
        $this->client->request('GET', '/admin/cms');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/api/commune-proche?lat=abc&lng=0');
        self::assertResponseStatusCodeSame(400);
    }

    /** @param array<string, string> $data */
    private function post(string $action, array $data): void
    {
        $crawler = $this->client->request('GET', '/admin/cms');
        $form = $crawler->filter('form[action="'.$action.'"]')->first();
        $this->client->request('POST', $action, $data + ['_token' => (string) $form->filter('input[name="_token"]')->attr('value')]);
        self::assertResponseRedirects();
    }
}
