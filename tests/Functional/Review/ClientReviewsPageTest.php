<?php

declare(strict_types=1);

namespace App\Tests\Functional\Review;

use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\Billing\Application\Credit\CreditLedger;
use App\Identity\Application\Command\RegisterUser;
use App\Quote\Application\Client\AcceptQuote;
use App\Quote\Application\Editor\SaveQuoteDraft;
use App\Quote\Application\Sending\SendQuote;
use App\QuoteRequest\Application\Dispatch\DispatchQuoteRequest;
use App\QuoteRequest\Application\Submission\SubmitQuoteRequest;
use App\Review\Application\Review\SendReviewInvitations;
use App\Shared\Application\Storage\ScanQuarantinedFile;
use App\Shared\Domain\Storage\FileQuarantined;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\FileFormField;

/**
 * T-704b — C06 « Mes avis » : prestation à évaluer (fenêtre RG-034), formulaire (note, sous-notes, texte, photo),
 * modération a priori, modification, suppression, photo réservée à l'auteur avant publication ; invitations E-16.
 */
final class ClientReviewsPageTest extends WebTestCase
{
    use ArtisanDossier;

    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000fa501';
    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000aa501';
    private const string REQUEST = '0190f5e4-0000-7000-8000-0000000fa502';
    private const string QUOTE = '0190f5e4-0000-7000-8000-0000000fa503';
    private const string A3 = '0190f5e4-0000-7000-8000-0000000fa5a3';

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
        $this->dossierDispatch(new RegisterUser(self::CLIENT, 'camille.c06@exemple.test', 'cheval agrafe batterie', 'client', 'Camille', 'Fictive'));
        $connection->executeStatement("UPDATE identity.user_account SET status = 'active', email_verified_at = date_trunc('second', now()) WHERE id IN (:a, :b)", ['a' => self::DOSSIER_USER, 'b' => self::CLIENT]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $job = $this->dossierValue("SELECT id FROM catalog.job WHERE slug = 'plombier'");
        $this->dossierDispatch(new SubmitQuoteRequest(self::REQUEST, self::CLIENT, $job, '76351', '76600', '1 rue Fictive', 'repair', 'Fuite sous l’évier de la cuisine depuis ce matin (fictif).', [], '500_1000', 'week', null, title: 'Fuite sous l’évier'));
        $this->dossierDispatch(new DispatchQuoteRequest(self::REQUEST));
        $this->dossierDispatch(new SaveQuoteDraft(self::DOSSIER_USER, self::REQUEST, self::QUOTE, ['subject' => 'Réparation de la fuite', 'lines' => [
            ['type' => 'labour', 'label' => 'Recherche de fuite', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '90', 'vat' => '1000'],
            ['type' => 'travel', 'label' => 'Déplacement', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '0', 'vat' => '2000'],
        ]]));
        $ledger = self::getContainer()->get(CreditLedger::class);
        \assert($ledger instanceof CreditLedger);
        $ledger->grantFree('0190f5e4-0000-7000-8000-0000000fa505', $this->artisan, self::DOSSIER_USER, 3, null, new \DateTimeImmutable());
        $this->dossierDispatch(new SendQuote(self::DOSSIER_USER, self::REQUEST));
        $this->dossierDispatch(new AcceptQuote(self::CLIENT, self::QUOTE));
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $crawler = $this->client->request('GET', '/connexion');
        $this->client->request('POST', '/connexion', ['_username' => 'camille.c06@exemple.test', '_password' => 'cheval agrafe batterie', '_csrf_token' => (string) $crawler->filter('input[name="_csrf_token"]')->attr('value')]);
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    public function testGiveEditAndDeleteReview(): void
    {
        $page = $this->client->request('GET', '/espace-client/avis');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Avis possible à partir du', $page->filter('.tm-reviews__give')->text(), 'fenêtre pas encore ouverte (acceptation + 7 j)');

        $this->connection->executeStatement("INSERT INTO quote.appointment (id, quote_id, starts_at, ends_at, status, proposed_by, confirmed_at, completed_at, created_at) VALUES (:id, :q, now() - interval '2 days', now() - interval '2 days' + interval '1 hour', 'completed', 'client', now() - interval '4 days', now() - interval '1 day', now() - interval '5 days')", ['id' => self::A3, 'q' => self::QUOTE]);
        $page = $this->client->request('GET', '/espace-client/avis');
        self::assertStringContainsString('À donner (1)', $page->filter('.tm-reviews__tabs')->text());
        $form = $this->client->click($page->filter('.tm-reviews__give')->selectLink('Donner mon avis')->link())->filter('form#avis')->form();
        $this->client->submit($form, ['note' => '4', 'titre' => 'Bien (fictif)', 'avis' => 'Trop court.']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('au moins 50 caractères', $this->client->getCrawler()->filter('#avis')->text());

        $form = $this->client->getCrawler()->filter('form#avis')->form();
        $field = $form['photos'];
        $field = \is_array($field) ? $field[0] : $field;
        self::assertInstanceOf(FileFormField::class, $field);
        $field->upload(StorageSamples::file(StorageSamples::png()));
        $this->client->submit($form, ['note' => '4', 'sous_quality' => '5', 'titre' => 'Remplacement rapide et propre (fictif)', 'avis' => 'Intervention rapide, travail soigné et chantier laissé propre. Je recommande vivement (fictif).', 'recommande' => 'oui']);
        self::assertResponseRedirects('/espace-client/avis?onglet=publies', 303);
        $list = $this->client->followRedirect();
        self::assertStringContainsString('En modération', $list->filter('.tm-reviews__mine')->text());
        $id = DbalValue::string($this->connection->fetchOne('SELECT id FROM review.review'));
        $photo = DbalValue::string($this->connection->fetchOne('SELECT photos->>0 FROM review.review'));

        // Photo : lisible par l'auteur une fois analysée, pas par un visiteur tant que l'avis n'est pas publié.
        $scan = self::getContainer()->get(ScanQuarantinedFile::class);
        \assert($scan instanceof ScanQuarantinedFile);
        $scan(new FileQuarantined($photo, 'review_photo', new \DateTimeImmutable()));
        $this->client->request('GET', '/avis/'.$id.'/photos/'.$photo);
        self::assertResponseIsSuccessful();

        $edit = $this->client->request('GET', '/espace-client/avis/'.$id.'/modifier');
        $this->client->submit($edit->filter('form#avis')->form(), ['note' => '5']);
        self::assertResponseRedirects('/espace-client/avis?onglet=publies', 303);
        self::assertSame(5, DbalValue::int($this->connection->fetchOne('SELECT rating FROM review.review')));
        self::assertSame(1, DbalValue::int($this->connection->fetchOne('SELECT jsonb_array_length(photos) FROM review.review')), 'photo conservée');

        $list = $this->client->request('GET', '/espace-client/avis?onglet=publies');
        $this->client->submit($list->filter('.tm-reviews__delete form')->form());
        self::assertSame(0, DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM review.review')));
    }

    public function testInvitationThenReminder(): void
    {
        $this->connection->executeStatement("INSERT INTO quote.appointment (id, quote_id, starts_at, ends_at, status, proposed_by, confirmed_at, completed_at, created_at) VALUES (:id, :q, now() - interval '2 days', now() - interval '2 days' + interval '1 hour', 'completed', 'client', now() - interval '4 days', now() - interval '1 day', now() - interval '5 days')", ['id' => self::A3, 'q' => self::QUOTE]);
        $this->dossierDispatch(new SendReviewInvitations());
        $this->dossierDispatch(new SendReviewInvitations());
        self::assertSame(1, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM notification.delivery WHERE code = 'E-16' AND channel = 'email'")));
        $this->connection->executeStatement("UPDATE review.invitation SET invited_at = now() - interval '7 days'");
        $this->dossierDispatch(new SendReviewInvitations());
        self::assertSame(2, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM notification.delivery WHERE code = 'E-16' AND channel = 'email'")));
        self::assertNotNull($this->connection->fetchOne('SELECT reminded_at FROM review.invitation'));
    }
}
