<?php

declare(strict_types=1);

namespace App\Tests\Functional\QuoteRequest;

use App\Identity\Application\Command\RegisterUser;
use App\QuoteRequest\Application\EventHandler\SendRequestSubmitted;
use App\QuoteRequest\Domain\Request\Event\QuoteRequestSubmitted;
use App\QuoteRequest\UI\Http\RequestTunnelController;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\Fake\AcceptingHumanVerification;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** P11 étapes 4 à 6 (T-402b) : lieu (BAN, adresse scellée), coordonnées (RG-040, téléphone A02), envoi, confirmation, E-10, brouillon rattaché. */
final class RequestSubmissionTest extends WebTestCase
{
    use ArtisanDossier;

    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000f1001';
    private const string EMAIL = 'camille.tunnel@exemple.test';
    private const string PASSWORD = 'cheval agrafe batterie';

    private KernelBrowser $client;
    private Connection $connection;
    private string $jobId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $this->connection = $connection;
        $this->prepareReferentials();
        $this->dossierDispatch(new RegisterUser(self::CLIENT, self::EMAIL, self::PASSWORD, 'client', 'Camille', 'Exemple'));
        $this->jobId = $this->dossierValue("SELECT id FROM catalog.job WHERE slug = 'plombier'");
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    public function testVerifiedClientSubmitsTheRequest(): void
    {
        $this->verifyEmail();
        $this->login();
        $crawler = $this->walkToPlace();

        $crawler = $this->client->request('POST', '/demande-de-devis/4', ['_token' => $this->token($crawler), 'action' => 'continue', 'address' => 'adresse inconnue', 'timing' => 'date', 'desired_date' => '2001-01-01']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Sélectionnez une adresse dans la liste.', trim($crawler->filter('#address_error')->text()));
        self::assertSame('Choisissez une date à venir.', trim($crawler->filter('#desired_date_error')->text()));
        self::assertSame('Indiquez votre statut.', trim($crawler->filter('#occupant_status_error')->text()));

        $this->client->request('POST', '/demande-de-devis/4', ['_token' => $this->token($crawler), 'action' => 'continue', 'address' => '12 rue fictive', 'timing' => 'week', 'preferred_slots' => ['morning', 'evening', 'inconnu'], 'occupant_status' => 'owner']);
        self::assertResponseRedirects('/demande-de-devis/5', 303);
        $stored = DbalValue::string($this->connection->fetchOne('SELECT payload::text FROM request.request_draft'));
        self::assertStringNotContainsString('Fictive', $stored, 'RG-022bis : adresse scellée au repos');

        $crawler = $this->client->followRedirect();
        self::assertSame(self::EMAIL, $crawler->filter('#email')->attr('value'));
        $crawler = $this->client->request('POST', '/demande-de-devis/5', ['_token' => $this->token($crawler), 'action' => 'continue', 'contact_preference' => 'phone_after_acceptance']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('requis pour votre première demande', $crawler->filter('.tm-request__main')->text());
        $this->client->request('POST', '/demande-de-devis/5', ['_token' => $this->token($crawler), 'action' => 'continue', 'contact_preference' => 'phone_after_acceptance', 'phone' => '06 12 34 56 78']);
        self::assertResponseRedirects('/demande-de-devis/6', 303);

        $crawler = $this->client->followRedirect();
        $recap = $crawler->filter('.tm-request__main')->text();
        foreach (['PrestationPlombier', 'ProjetDépannage', 'LocalisationLe Havre (76600)', 'BudgetNon précisé', 'PériodeSous 1 semaine', 'Photos1 fichier joint', 'jusqu’à 3 devis gratuits'] as $expected) {
            self::assertStringContainsString($expected, $recap);
        }

        $crawler = $this->client->request('POST', '/demande-de-devis/6', ['_token' => $this->token($crawler), 'action' => 'send', 'cf-turnstile-response' => AcceptingHumanVerification::HUMAN]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Cochez cette case pour envoyer votre demande.', trim($crawler->filter('#confirm_error')->text()), 'Figma 5:219 : exactitude confirmée');
        $crawler = $this->client->request('POST', '/demande-de-devis/6', ['_token' => $this->token($crawler), 'action' => 'send', 'confirm' => '1', 'cf-turnstile-response' => 'robot']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('vérification anti-robot a échoué', $crawler->filter('.tm-form-alert')->text());

        $this->client->request('POST', '/demande-de-devis/6', ['_token' => $this->token($crawler), 'action' => 'send', 'confirm' => '1', 'cf-turnstile-response' => AcceptingHumanVerification::HUMAN]);
        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertSame(1, preg_match('~^/demande-de-devis/envoyee/([0-9a-f-]{36})$~', $location, $m));
        $requestId = $m[1];

        $row = $this->connection->fetchAssociative('SELECT status, client_id, contact_preference, occupant_status, preferred_slots::text AS slots, questionnaire_version, city_insee, postal_code FROM request.quote_request WHERE id = :id', ['id' => $requestId]);
        self::assertSame(['status' => 'open', 'client_id' => self::CLIENT, 'contact_preference' => 'phone_after_acceptance', 'occupant_status' => 'owner', 'slots' => '["morning", "evening"]', 'questionnaire_version' => null, 'city_insee' => '76351', 'postal_code' => '76600'], $row);
        self::assertStringNotContainsString('Fictive', DbalValue::string($this->connection->fetchOne('SELECT address_sealed FROM request.request_address WHERE request_id = :id', ['id' => $requestId])));
        self::assertTrue((bool) $this->connection->fetchOne('SELECT geocoded FROM request.request_address WHERE request_id = :id', ['id' => $requestId]));
        self::assertSame(1, DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM request.request_attachment WHERE request_id = :id', ['id' => $requestId])), 'photo reprise');
        self::assertSame(0, DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM request.request_draft')), 'brouillon supprimé');
        self::assertSame('+33612345678', DbalValue::string($this->connection->fetchOne('SELECT phone FROM identity.user_account WHERE id = :id', ['id' => self::CLIENT])), 'téléphone porté par le compte (A02)');

        $crawler = $this->client->request('GET', $location);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Votre demande a bien été envoyée.');
        self::assertMatchesRegularExpression('/Demande : plombier Zone : Le Havre \(76600\) · Jusqu’à 3 devis possibles · Référence : DM-\d{4}-\d{6}/', $crawler->filter('.tm-request__sent-card')->text());

        $handler = self::getContainer()->get(SendRequestSubmitted::class);
        \assert($handler instanceof SendRequestSubmitted);
        $handler(new QuoteRequestSubmitted($requestId, self::CLIENT, $this->jobId, '76351', 'open', 3, null, true, new \DateTimeImmutable()));
        self::assertSame(1, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM notification.delivery WHERE code = 'E-10' AND channel = 'email'")), 'E-10 envoyé');

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $location);
        self::assertResponseStatusCodeSame(404, 'confirmation réservée au client');
    }

    public function testVisitorDraftIsAdoptedAtLogin(): void
    {
        $this->verifyEmail();
        $crawler = $this->walkToPlace();
        $this->client->request('POST', '/demande-de-devis/4', ['_token' => $this->token($crawler), 'action' => 'continue', 'address' => '3 place de l essai', 'timing' => 'flexible', 'occupant_status' => 'tenant']);
        $crawler = $this->client->followRedirect();
        self::assertSelectorExists('#first_name', 'Figma 5:162 : formulaire de création de compte');
        self::assertSame('/connexion?redirect=/demande-de-devis/5', $crawler->selectLink('Se connecter')->attr('href'));

        $this->client->request('POST', '/demande-de-devis/5', ['_token' => $this->token($crawler), 'action' => 'continue', 'contact_preference' => 'messaging_only']);
        self::assertResponseStatusCodeSame(422, 'un compte est nécessaire');

        $this->login();
        $crawler = $this->client->request('GET', '/demande-de-devis/5');
        self::assertResponseIsSuccessful();
        self::assertSame(self::EMAIL, $crawler->filter('#email')->attr('value'), 'brouillon rattaché au compte');
        self::assertSame(self::CLIENT, DbalValue::string($this->connection->fetchOne('SELECT owner_user_id FROM request.request_draft')));
        self::assertNull($this->client->getCookieJar()->get(RequestTunnelController::COOKIE, '/demande-de-devis'), 'jeton du visiteur oublié');
    }

    public function testUnverifiedEmailDefersSubmission(): void
    {
        $this->login();
        $crawler = $this->walkToPlace();
        $this->client->request('POST', '/demande-de-devis/4', ['_token' => $this->token($crawler), 'action' => 'continue', 'address' => '12 rue fictive', 'timing' => 'urgent', 'occupant_status' => 'owner']);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Validez votre adresse e-mail', $crawler->filter('.tm-request__main')->text());
        $this->client->request('POST', '/demande-de-devis/5', ['_token' => $this->token($crawler), 'action' => 'continue', 'contact_preference' => 'messaging_only', 'phone' => '06 12 34 56 78']);
        $crawler = $this->client->followRedirect();
        $this->client->request('POST', '/demande-de-devis/6', ['_token' => $this->token($crawler), 'action' => 'send', 'confirm' => '1', 'cf-turnstile-response' => AcceptingHumanVerification::HUMAN]);
        self::assertResponseRedirects('/demande-de-devis/6', 303);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Plus qu’une étape : activez votre compte', $crawler->filter('.tm-request__main')->text(), 'RG-040 : envoi différé à la validation de l’e-mail');
        self::assertSame(0, DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM request.quote_request')));
        self::assertNotNull($this->connection->fetchOne('SELECT submit_requested_at FROM request.request_draft'));
    }

    /** Étapes 1 à 3 (métier, détails, une photo) ; renvoie la page de l'étape 4. */
    private function walkToPlace(): Crawler
    {
        $crawler = $this->client->request('GET', '/demande-de-devis/1');
        $this->client->request('POST', '/demande-de-devis/1', ['_token' => $this->token($crawler), 'action' => 'continue', 'job_id' => $this->jobId, 'project_type' => 'repair']);
        $this->client->request('POST', '/demande-de-devis/2', ['_token' => $this->token($crawler), 'action' => 'continue', 'description' => 'Fuite sous l’évier de la cuisine depuis ce matin (demande fictive).']);
        self::assertResponseRedirects('/demande-de-devis/3', 303);
        $this->client->request('POST', '/demande-de-devis/fichiers', ['_token' => $this->token($crawler)], ['files' => [new UploadedFile(StorageSamples::file(StorageSamples::png(400, 300)), 'evier.png', 'image/png', null, true)]]);
        $this->client->request('POST', '/demande-de-devis/3', ['_token' => $this->token($crawler), 'action' => 'continue']);
        self::assertResponseRedirects('/demande-de-devis/4', 303);

        return $this->client->followRedirect();
    }

    private function verifyEmail(): void
    {
        $this->connection->executeStatement("UPDATE identity.user_account SET status = 'active', email_verified_at = date_trunc('second', now()) WHERE id = :id", ['id' => self::CLIENT]);
    }

    private function login(): void
    {
        $crawler = $this->client->request('GET', '/connexion');
        $this->client->request('POST', '/connexion', ['_username' => self::EMAIL, '_password' => self::PASSWORD, '_csrf_token' => (string) $crawler->filter('input[name="_csrf_token"]')->attr('value')]);
        self::assertResponseRedirects();
    }

    private function token(Crawler $crawler): string
    {
        return (string) $crawler->filter('input[name="_token"]')->first()->attr('value');
    }
}
