<?php

declare(strict_types=1);

namespace App\Tests\Functional\QuoteRequest;

use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\Identity\Application\Command\RegisterUser;
use App\QuoteRequest\Application\Dispatch\DispatchQuoteRequest;
use App\QuoteRequest\Application\Submission\SubmitQuoteRequest;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** PR02 (Figma 159:3176) : espace pro, cartes de demande (RG-112), filtres, « Ignorer » / « Rétablir », photo protégée. */
final class ProRequestsPageTest extends WebTestCase
{
    use ArtisanDossier;

    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000e9501';
    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a9501';
    private const string URGENT = '0190f5e4-0000-7000-8000-0000000e9601';
    private const string LATER = '0190f5e4-0000-7000-8000-0000000e9602';

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $this->connection = $connection;
        $this->prepareReferentials();
        $profileId = $this->submittedDossier(200);
        $this->uploadPiece('kbis');
        $this->uploadPiece('insurance_rc');
        $this->dossierDispatch(new ApproveArtisan($profileId, self::ADMIN));
        $connection->executeStatement("UPDATE identity.user_account SET status = 'active', email_verified_at = date_trunc('second', now()) WHERE id = '".self::DOSSIER_USER."'");
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->dossierDispatch(new RegisterUser(self::CLIENT, 'camille.page@exemple.test', 'cheval agrafe batterie', 'client', 'Camille', 'Discrète'));
        $job = $this->dossierValue("SELECT id FROM catalog.job WHERE slug = 'plombier'");
        $this->dossierDispatch(new SubmitQuoteRequest(self::URGENT, self::CLIENT, $job, '76351', '76600', '1 rue Fictive', 'repair', 'Fuite sous l’évier de la cuisine depuis ce matin (fictif).', [], '500_1000', 'urgent', null, title: 'Fuite sous l’évier'));
        $this->dossierDispatch(new SubmitQuoteRequest(self::LATER, self::CLIENT, $job, '76351', '76600', '2 rue Fictive', 'installation', 'Pose d’un nouveau chauffe-eau dans le garage (fictif).', [], null, 'month', null));
        $this->dossierDispatch(new DispatchQuoteRequest(self::URGENT));
        $this->dossierDispatch(new DispatchQuoteRequest(self::LATER));

        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $crawler = $this->client->request('GET', '/connexion');
        $this->client->request('POST', '/connexion', ['_username' => 'dominique.verif@exemple.test', '_password' => 'cheval agrafe batterie', '_csrf_token' => (string) $crawler->filter('input[name="_csrf_token"]')->attr('value')]);
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    public function testListFiltersIgnoreAndRestore(): void
    {
        $crawler = $this->client->request('GET', '/espace-pro/demandes');
        self::assertResponseIsSuccessful();
        self::assertSame('Mes demandes', $crawler->filter('h1')->text());
        self::assertStringContainsString('Dominique Exemple', $crawler->filter('.tm-space__profile')->text());
        self::assertStringStartsWith('2', trim($crawler->filter('.tm-space__menu .tm-space__link.is-active .tm-space__count')->text()), 'compteur « Mes demandes » : non lues');
        self::assertCount(2, $crawler->filter('.tm-pro-request'));
        $card = $crawler->filter('.tm-pro-request')->reduce(static fn ($c): bool => str_contains($c->text(), 'Fuite sous l’évier'));
        self::assertStringContainsString('Camille', $card->text(), 'RG-112 : prénom');
        self::assertStringNotContainsString('Discrète', $crawler->filter('.tm-pro-requests')->text(), 'jamais le nom');
        self::assertStringNotContainsString('rue Fictive', $crawler->filter('.tm-pro-requests')->text(), 'jamais l’adresse');
        self::assertStringContainsString('Le Havre (76600)', $card->text());
        self::assertStringContainsString('Budget : Entre 500 € et 1 000 €', $card->text());
        self::assertStringContainsString('0/3 devis déjà envoyés', $card->text());
        self::assertStringContainsString('Nouveau', $card->text());
        self::assertSame('2', trim($crawler->filter('.tm-pro-figure.is-new')->text()));
        self::assertSame('1', trim($crawler->filter('.tm-pro-figure.is-urgent')->text()));

        $crawler = $this->client->request('GET', '/espace-pro/demandes?delai=urgent');
        self::assertCount(1, $crawler->filter('.tm-pro-request'));
        self::assertCount(1, $crawler->filter('.tm-pro-reset'), 'Réinitialiser');
        $crawler = $this->client->request('GET', '/espace-pro/demandes?q=chauffe');
        self::assertCount(0, $crawler->filter('.tm-pro-request'), 'recherche sur le titre, le métier, la commune, le numéro');
        self::assertStringContainsString('Aucune demande ne correspond', $crawler->text());

        $crawler = $this->client->request('GET', '/espace-pro/demandes');
        $form = $crawler->filter('.tm-pro-request')->reduce(static fn ($c): bool => str_contains($c->text(), 'Fuite sous l’évier'))->filter('.tm-pro-ignore form');
        $this->client->request('POST', (string) $form->attr('action'), ['_token' => (string) $form->filter('input[name="_token"]')->attr('value'), 'reason' => 'too_far', 'back' => '/espace-pro/demandes']);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Demande ignorée', $crawler->filter('.tm-toasts')->text());
        self::assertCount(1, $crawler->filter('.tm-pro-request'));

        $crawler = $this->client->request('GET', '/espace-pro/demandes?statut=ignoree');
        self::assertStringContainsString('Ignorée', $crawler->filter('.tm-pro-request')->text());
        $restore = $crawler->filter('.tm-pro-request form');
        $this->client->request('POST', (string) $restore->attr('action'), ['_token' => (string) $restore->filter('input[name="_token"]')->attr('value'), 'restore' => '1']);
        self::assertCount(2, $this->client->followRedirect()->filter('.tm-pro-request'));

        $this->client->request('GET', '/espace-pro/demandes/'.self::URGENT.'/photos/0190f5e4-0000-7000-8000-000000000000');
        self::assertResponseStatusCodeSame(404, 'fichier hors de la demande');
    }

    public function testClientCannotOpenTheProSpace(): void
    {
        $this->client->getCookieJar()->clear();
        $this->connection->executeStatement("UPDATE identity.user_account SET status = 'active', email_verified_at = date_trunc('second', now()) WHERE id = '".self::CLIENT."'");
        $crawler = $this->client->request('GET', '/connexion');
        $this->client->request('POST', '/connexion', ['_username' => 'camille.page@exemple.test', '_password' => 'cheval agrafe batterie', '_csrf_token' => (string) $crawler->filter('input[name="_csrf_token"]')->attr('value')]);
        $this->client->request('GET', '/espace-pro/demandes');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/espace-pro/demandes/'.self::URGENT.'/photos/0190f5e4-0000-7000-8000-000000000000');
        self::assertResponseStatusCodeSame(403);
    }
}
