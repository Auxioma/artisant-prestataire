<?php

declare(strict_types=1);

namespace App\Tests\Functional\Search;

use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanVerified;
use App\Search\Application\EventHandler\ProjectArtisanForSearch;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** P10 : URL canonique et 301, 404 hors statut public, badge et justificatifs (RG-032), LocalBusiness, téléphone au clic (RG-030). */
final class ArtisanProfilePageTest extends WebTestCase
{
    use ArtisanDossier;

    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a0001';

    private KernelBrowser $client;
    private string $profileId;
    private string $url;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->prepareReferentials();
        // Siège à Caen (Sirene fictif) ; dossier non encore validé.
        $this->profileId = $this->submittedDossier(rcDays: 200);
        $this->url = '/pro/atelier-fictif-caen-'.$this->profileId;
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    public function testOnlyPublicArtisansHaveAPage(): void
    {
        $this->client->request('GET', $this->url);
        self::assertResponseStatusCodeSame(404, 'dossier en attente de vérification');

        $this->approve();
        $this->client->request('GET', $this->url);
        self::assertResponseIsSuccessful();

        $this->sql("UPDATE artisan.artisan_profile SET hidden_at = date_trunc('second', now()), hidden_reason = 'assurance échue' WHERE id = '".$this->profileId."'");
        $this->client->request('GET', $this->url);
        self::assertResponseStatusCodeSame(404, 'profil masqué (RG-130)');
        $this->client->request('GET', '/pro/x-------------------------------------');
        self::assertResponseStatusCodeSame(404);
    }

    public function testCanonicalUrlAndRedirects(): void
    {
        $this->approve();
        $this->client->request('GET', '/pro/'.$this->profileId);
        self::assertResponseRedirects($this->url, 301);
        $this->client->request('GET', '/pro/ancien-nom-'.$this->profileId);
        self::assertResponseRedirects($this->url, 301);

        $crawler = $this->client->request('GET', '/recherche?metier=plombier&lieu=76341&rayon=100');
        self::assertSame($this->url, $crawler->filter('.tm-card-row__name a')->attr('href'), 'les résultats pointent vers l’URL canonique');
    }

    public function testPageContent(): void
    {
        $this->approve();
        $crawler = $this->client->request('GET', $this->url);

        self::assertSelectorTextContains('h1', 'Atelier Fictif');
        self::assertSame('http://localhost'.$this->url, $crawler->filter('link[rel="canonical"]')->attr('href'));
        self::assertSame('pro_profile_view', $crawler->filter('.tm-profile')->attr('data-analytics-auto-event-value'));
        self::assertStringContainsString('Pièce d’identité du dirigeant', $crawler->filter('.tm-profile__verified-panel')->text());
        self::assertStringContainsString('Nouveau', $crawler->filter('.tm-profile__badges')->text(), 'aucune note sans avis');
        self::assertStringContainsString('Caen (14)', $crawler->filter('.tm-profile__facts')->text());
        self::assertStringContainsString('L111-7-2', $crawler->filter('#avis')->text());
        self::assertStringContainsString('900 000 001 00017', $crawler->filter('#informations')->text());

        $credential = $crawler->filter('.tm-profile__credential')->text();
        self::assertStringContainsString('Responsabilité civile professionnelle', $credential);
        self::assertStringContainsString('Justificatif vérifié', $credential);

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('06 11 22 33 44', $html, 'jamais de téléphone dans la page (RG-030)');
        self::assertStringNotContainsString('dominique.verif', $html);
        self::assertCount(0, $crawler->filter('form[action$="/telephone"]'), 'option « afficher mon téléphone » désactivée par défaut');

        $jsonLd = json_decode($crawler->filter('script[type="application/ld+json"]')->last()->text(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($jsonLd);
        self::assertSame('LocalBusiness', $jsonLd['@type']);
        self::assertIsArray($jsonLd['address']);
        self::assertSame('Caen', $jsonLd['address']['addressLocality']);
        self::assertArrayNotHasKey('aggregateRating', $jsonLd, 'pas de note agrégée sans avis');
    }

    public function testExpiredDocumentIsOnlyDeclared(): void
    {
        $this->approve();
        $this->sql("UPDATE artisan.document SET expires_on = current_date - 1 WHERE type = 'insurance_rc'");

        $crawler = $this->client->request('GET', $this->url);

        self::assertStringContainsString('Déclaré', $crawler->filter('.tm-profile__credential')->text(), 'RG-032 : justificatif échu');
        self::assertStringNotContainsString('Attestation d’assurance RC', $crawler->filter('.tm-profile__verified-panel')->text());
    }

    public function testPhoneIsRevealedOnClickAndTracked(): void
    {
        $this->approve();
        $this->sql("UPDATE artisan.artisan_profile SET phone_public = true WHERE id = '".$this->profileId."'");
        $this->sql("UPDATE identity.user_account SET status = 'active', email_verified_at = date_trunc('second', now()) WHERE id = '".self::DOSSIER_USER."'");
        $crawler = $this->client->request('GET', $this->url);
        $form = $crawler->filter('form[action$="/telephone"]');
        self::assertCount(1, $form);
        self::assertStringNotContainsString('06 11 22 33 44', (string) $this->client->getResponse()->getContent());

        $this->client->request('POST', '/pro/'.$this->profileId.'/telephone', ['_token' => 'csrf-token'], server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_ORIGIN' => 'https://evil.example', 'HTTP_REFERER' => 'https://evil.example/']);
        self::assertResponseStatusCodeSame(403, 'requête d’une autre origine (CSRF sans état)');

        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $this->client->request('POST', '/pro/'.$this->profileId.'/telephone', ['_token' => 'csrf-token'], server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        self::assertSame(['phone' => '06 11 22 33 44', 'href' => 'tel:0611223344'], json_decode((string) $this->client->getResponse()->getContent(), true));
        self::assertSame('1', $this->dossierValue("SELECT count(*) FROM artisan.phone_reveal WHERE artisan_id = '".$this->profileId."' AND visitor_hash IS NOT NULL"));

        // Sans JavaScript : la fiche est réaffichée avec le numéro.
        $this->client->request('POST', '/pro/'.$this->profileId.'/telephone', ['_token' => 'csrf-token']);
        self::assertResponseRedirects();
        $this->client->followRedirects();
        $crawler = $this->client->request('GET', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertSame('tel:0611223344', $crawler->filter('.tm-profile__phone a')->attr('href'));

        $this->sql("UPDATE artisan.artisan_profile SET phone_public = false WHERE id = '".$this->profileId."'");
        $this->client->followRedirects(false);
        $this->client->request('POST', '/pro/'.$this->profileId.'/telephone', ['_token' => 'csrf-token'], server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(404, 'option désactivée par l’artisan');
    }

    private function approve(): void
    {
        $this->uploadPiece('kbis');
        $this->uploadPiece('insurance_rc');
        $this->dossierDispatch(new ApproveArtisan($this->profileId, self::ADMIN));
        $project = self::getContainer()->get(ProjectArtisanForSearch::class);
        \assert($project instanceof ProjectArtisanForSearch);
        $project(new ArtisanVerified($this->profileId, self::DOSSIER_USER, self::ADMIN, new \DateTimeImmutable()));
    }

    private function sql(string $statement): void
    {
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $connection->executeStatement($statement);
        self::getContainer()->get('doctrine')->getManager()->clear();
    }
}
