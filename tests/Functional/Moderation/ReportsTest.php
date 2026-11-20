<?php

declare(strict_types=1);

namespace App\Tests\Functional\Moderation;

use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\Identity\Application\Command\RegisterUser;
use App\QuoteRequest\Application\Dispatch\DispatchQuoteRequest;
use App\QuoteRequest\Application\Submission\SubmitQuoteRequest;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\AdminSession;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** T-706a — BO05.2 « Signalements » : profil (client), demande (artisan destinataire), refus, décision, réponse E-27. */
final class ReportsTest extends WebTestCase
{
    use AdminSession;
    use ArtisanDossier;

    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000f7b01';
    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a7b01';
    private const string MODERATOR = '0190f5e4-0000-7000-8000-0000000a7b02';
    private const string REQUEST = '0190f5e4-0000-7000-8000-0000000f7b02';

    private KernelBrowser $client;
    private Connection $connection;
    private string $artisan;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $this->connection = $connection;
        $this->prepareReferentials();
        $this->artisan = $this->submittedDossier(200);
        $this->uploadPiece('kbis');
        $this->uploadPiece('insurance_rc');
        $this->dossierDispatch(new ApproveArtisan($this->artisan, self::ADMIN));
        $this->dossierDispatch(new RegisterUser(self::CLIENT, 'camille.bo052@exemple.test', 'cheval agrafe batterie', 'client', 'Camille', 'Fictive'));
        $connection->executeStatement("UPDATE identity.user_account SET status = 'active', email_verified_at = date_trunc('second', now()) WHERE id IN (:a, :b)", ['a' => self::DOSSIER_USER, 'b' => self::CLIENT]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $job = $this->dossierValue("SELECT id FROM catalog.job WHERE slug = 'plombier'");
        $this->dossierDispatch(new SubmitQuoteRequest(self::REQUEST, self::CLIENT, $job, '76351', '76600', '1 rue Fictive', 'repair', 'Fuite sous l’évier de la cuisine depuis ce matin (fictif).', [], '500_1000', 'week', null, title: 'Fuite sous l’évier'));
        $this->dossierDispatch(new DispatchQuoteRequest(self::REQUEST));
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    private function login(string $email): void
    {
        $crawler = $this->client->request('GET', '/connexion');
        $this->client->request('POST', '/connexion', ['_username' => $email, '_password' => 'cheval agrafe batterie', '_csrf_token' => (string) $crawler->filter('input[name="_csrf_token"]')->attr('value')]);
    }

    private function reports(): int
    {
        return DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM moderation.report WHERE status = 'open'"));
    }

    public function testReportProfileAndRequestThenDecide(): void
    {
        // Visiteur : connexion demandée.
        $this->client->request('GET', '/signaler/profil/'.$this->artisan);
        self::assertResponseRedirects();
        self::assertStringContainsString('/connexion', (string) $this->client->getResponse()->headers->get('Location'));

        $this->login('camille.bo052@exemple.test');
        $page = $this->client->request('GET', '/signaler/profil/'.$this->artisan.'?retour=/pro/x');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Profil de', $page->filter('.tm-report__content')->text());
        $form = $page->selectButton('Envoyer le signalement')->form();
        $this->client->submit($form, ['details' => 'Coordonnées douteuses (fictif).']);
        self::assertResponseStatusCodeSame(422, 'motif obligatoire');
        $this->client->submit($form, ['reason' => 'fraude', 'details' => 'Ce professionnel demande un paiement hors plateforme (fictif).']);
        self::assertResponseRedirects('/pro/x');
        $this->client->submit($form, ['reason' => 'fraude']);
        self::assertResponseStatusCodeSame(422, 'un seul signalement ouvert');
        $this->client->request('GET', '/signaler/demande/'.self::REQUEST);
        self::assertResponseStatusCodeSame(404, 'le client ne « reçoit » pas sa demande');
        self::assertSame(1, $this->reports());

        // L'artisan destinataire signale la demande ; il ne peut pas signaler son propre profil.
        $this->client->getCookieJar()->clear();
        $this->login('dominique.verif@exemple.test');
        $page = $this->client->request('GET', '/signaler/demande/'.self::REQUEST);
        self::assertResponseIsSuccessful();
        $this->client->submit($page->selectButton('Envoyer le signalement')->form(), ['reason' => 'hors_sujet']);
        self::assertResponseRedirects('/');
        $page = $this->client->request('GET', '/signaler/profil/'.$this->artisan);
        $this->client->submit($page->selectButton('Envoyer le signalement')->form(), ['reason' => 'autre', 'details' => 'Test (fictif).']);
        self::assertStringContainsString('votre propre contenu', $this->client->getCrawler()->filter('#report_error')->text());
        self::assertSame(2, $this->reports());
        $this->client->getCookieJar()->clear();

        // BO05.2 : file, détail, décision et réponse au signalant.
        $this->adminSession($this->client, self::MODERATOR, 'moderator');
        $page = $this->client->request('GET', '/admin/moderation/signalements');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Signalements (2)', $page->filter('.tm-admin-tab.is-active')->text());
        self::assertCount(2, $page->filter('.tm-moderation__queue li'));
        self::assertStringContainsString('paiement hors plateforme', $page->filter('.tm-moderation__detail')->text());
        self::assertCount(1, $page->filter('.tm-moderation__detail a[href*="/admin/artisans/"]'));
        $form = $page->filter('.tm-moderation__detail')->selectButton('Marquer comme traité')->form();
        $this->client->submit($form, ['response' => 'Nous avons contacté ce professionnel (fictif).']);
        self::assertResponseRedirects('/admin/moderation/signalements');
        self::assertSame(1, $this->reports());
        self::assertSame('sent', DbalValue::string($this->connection->fetchOne("SELECT status FROM notification.delivery WHERE code = 'E-27' AND channel = 'email'")));
        self::assertSame('actioned', DbalValue::string($this->connection->fetchOne("SELECT status FROM moderation.report WHERE subject_type = 'profil'")));
    }
}
