<?php

declare(strict_types=1);

namespace App\Tests\Functional\Search;

use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanVerified;
use App\Search\Application\EventHandler\ProjectArtisanForSearch;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\StorageSamples;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** P02 : résultats (zone ET rayon), états vide / sans lieu, saisie libre, noindex, logo public. */
final class SearchPageTest extends WebTestCase
{
    use ArtisanDossier;

    private KernelBrowser $client;
    private string $profileId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->prepareReferentials();
        $this->dossierDispatch(new \App\Catalog\Application\Command\CreateJob('plomberie', 'Chauffe-eau'));
        // Artisan vérifié, siège à Caen (Sirene fictif), zone de 30 km autour d'Harfleur.
        $this->profileId = $this->submittedDossier();
        $this->uploadPiece('kbis');
        $this->uploadPiece('insurance_rc');
        $this->dossierDispatch(new ApproveArtisan($this->profileId, '0190f5e4-0000-7000-8000-0000000a0001'));
        $project = self::getContainer()->get(ProjectArtisanForSearch::class);
        \assert($project instanceof ProjectArtisanForSearch);
        $project(new ArtisanVerified($this->profileId, self::DOSSIER_USER, '0190f5e4-0000-7000-8000-0000000a0001', new \DateTimeImmutable()));
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    public function testResultsForAJobAndAPlace(): void
    {
        $crawler = $this->client->request('GET', '/recherche?metier=plombier&lieu=76341&rayon=100');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Plombier à Harfleur et alentours');
        self::assertSelectorTextContains('.tm-search__count', '1 professionnel vérifié');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, follow');
        $card = $crawler->filter('.tm-card-row')->text();
        self::assertStringContainsString('Atelier Fictif', $card);
        self::assertStringContainsString('Caen (14)', $card, 'commune du siège, jamais l’adresse (RG-022)');
        self::assertStringContainsString('Nouveau', $card, 'aucune note inventée sans avis');
        self::assertStringContainsString('métier (30 %)', $crawler->filter('.tm-search__ranking')->text(), 'critères de classement exposés (L111-7)');
        self::assertSame('search_results_view', $crawler->filter('.tm-search')->attr('data-analytics-auto-event-value'));
        self::assertNotNull($crawler->filter('input[name="metiers[]"][value="plombier"]')->attr('checked'), 'métier recherché coché dans les filtres');
        self::assertNull($crawler->filter('input[name="metiers[]"][value="chauffe-eau"]')->attr('checked'));
    }

    public function testEmptyStateOffersAWiderRadius(): void
    {
        $crawler = $this->client->request('GET', '/recherche?metier=plombier&lieu=76341&rayon=30');

        self::assertSelectorTextContains('.tm-empty', 'Aucun plombier trouvé dans un rayon de 30 km');
        self::assertStringContainsString('rayon=100', (string) $crawler->selectLink('Élargir à 100 km')->attr('href'), 'Caen est à plus de 30 km d’Harfleur');
        self::assertSame('search_no_result', $crawler->filter('.tm-search')->attr('data-analytics-auto-event-value'));
    }

    public function testFreeTextPlaceIsResolvedAndMissingPlaceIsAsked(): void
    {
        $crawler = $this->client->request('GET', '/recherche?lieu_libelle=Harfleur&rayon=100');
        self::assertSelectorTextContains('h1', 'Professionnels à Harfleur et alentours');
        self::assertSame('76341', $crawler->filter('input[name="lieu"]')->first()->attr('value'));

        $this->client->request('GET', '/recherche');
        self::assertSelectorTextContains('.tm-empty', 'Où cherchez-vous un professionnel ?');
        $this->client->request('GET', '/recherche?lieu_libelle=Zzzzzz');
        self::assertSelectorTextContains('.tm-empty', 'Ville introuvable');
    }

    public function testPublicLogoOnlyForPublicArtisans(): void
    {
        $this->client->request('GET', '/media/artisans/'.$this->profileId.'/logo');
        self::assertResponseStatusCodeSame(404, 'pas de logo déposé');
        $this->client->request('GET', '/media/artisans/------------------------------------/logo');
        self::assertResponseStatusCodeSame(404);
    }
}
