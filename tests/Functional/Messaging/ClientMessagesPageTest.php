<?php

declare(strict_types=1);

namespace App\Tests\Functional\Messaging;

use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\Billing\Application\Credit\CreditLedger;
use App\Identity\Application\Command\RegisterUser;
use App\Messaging\Application\Conversation\StartConversation;
use App\Quote\Application\Editor\SaveQuoteDraft;
use App\Quote\Application\Sending\SendQuote;
use App\QuoteRequest\Application\Dispatch\DispatchQuoteRequest;
use App\QuoteRequest\Application\Submission\SubmitQuoteRequest;
use App\Shared\Domain\Text\ContactDetector;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * C04 (Figma 160:644) : liste (non-lus, pastille du menu), fil (coordonnées masquées, lecture), réponse, fragment de
 * rafraîchissement, signalement, archivage, entrée depuis le comparateur C03 et question préalable depuis la fiche.
 */
final class ClientMessagesPageTest extends WebTestCase
{
    use ArtisanDossier;

    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000fb701';
    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000ab701';
    private const string REQUEST = '0190f5e4-0000-7000-8000-0000000fb702';
    private const string QUOTE = '0190f5e4-0000-7000-8000-0000000fb703';
    private const string CONVERSATION = '0190f5e4-0000-7000-8000-0000000fb704';

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
        $this->dossierDispatch(new RegisterUser(self::CLIENT, 'camille.c04@exemple.test', 'cheval agrafe batterie', 'client', 'Camille', 'Fictive'));
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
        $ledger->grantFree('0190f5e4-0000-7000-8000-0000000fb705', $this->artisan, self::DOSSIER_USER, 3, null, new \DateTimeImmutable());
        $this->dossierDispatch(new SendQuote(self::DOSSIER_USER, self::REQUEST));
        $this->dossierDispatch(new StartConversation(self::DOSSIER_USER, self::CONVERSATION, '0190f5e4-0000-7000-8000-0000000fb711', self::REQUEST, null, 'Bonjour, joignable au 06 12 34 56 78. Le devis est envoyé.'));
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $crawler = $this->client->request('GET', '/connexion');
        $this->client->request('POST', '/connexion', ['_username' => 'camille.c04@exemple.test', '_password' => 'cheval agrafe batterie', '_csrf_token' => (string) $crawler->filter('input[name="_csrf_token"]')->attr('value')]);
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    public function testListThreadReplyAndRefresh(): void
    {
        $page = $this->client->request('GET', '/espace-client/messages');
        self::assertResponseIsSuccessful();
        self::assertSame('Mes messages (1)', $page->filter('h1')->text());
        self::assertSame('1', $page->filter('.tm-cspace__menu .tm-cspace__link.is-active .tm-cspace__count')->text(), 'pastille du menu');
        self::assertStringContainsString('Fuite sous l’évier', $page->filter('.tm-msg-row')->text());

        $thread = $this->client->click($page->filter('.tm-msg-row')->link());
        self::assertStringContainsString(ContactDetector::MASK, $thread->filter('.tm-msg__body')->text());
        self::assertStringNotContainsString('06 12', $thread->filter('.tm-msg-thread')->text());
        self::assertStringContainsString('Coordonnées masquées', $thread->filter('.tm-msg__meta')->text());
        self::assertCount(0, $thread->filter('.tm-cspace__count'), 'lu à l’ouverture');

        $this->client->submit($thread->filter('form.tm-msg-composer')->form(), ['message' => 'Merci, mardi 10 h me convient.']);
        self::assertResponseRedirects('/espace-client/messages/'.self::CONVERSATION.'#dernier');
        $thread = $this->client->followRedirect();
        self::assertStringContainsString('Merci, mardi 10 h me convient.', $thread->filter('.tm-msg.is-mine')->text());

        $this->client->request('GET', '/espace-client/messages/'.self::CONVERSATION.'/fil');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('mardi 10 h', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $this->client->request('GET', '/espace-client/messages/0190f5e4-0000-7000-8000-0000000fb799');
        self::assertResponseStatusCodeSame(404);
    }

    public function testReportArchiveAndEntryPoints(): void
    {
        $thread = $this->client->request('GET', '/espace-client/messages/'.self::CONVERSATION);
        $this->client->submit($thread->selectButton('Envoyer le signalement')->form(), ['motif' => 'contact']);
        self::assertSame('contact', $this->connection->fetchOne('SELECT report_reason FROM messaging.message LIMIT 1'));
        $thread = $this->client->followRedirect();
        self::assertStringContainsString('Signalé à la modération', $thread->filter('.tm-msg-thread')->text());

        $this->client->submit($thread->selectButton('Archiver la conversation')->form());
        self::assertResponseRedirects('/espace-client/messages');
        self::assertCount(0, $this->client->followRedirect()->filter('.tm-msg-row'));
        self::assertCount(1, $this->client->request('GET', '/espace-client/messages?filtre=archived')->filter('.tm-msg-row'));

        $compare = $this->client->request('GET', '/espace-client/demandes/'.self::REQUEST);
        $this->client->click($compare->selectLink('Envoyer un message à '.DbalValue::string($this->connection->fetchOne('SELECT coalesce(trade_name, company_name) FROM artisan.artisan_profile WHERE id = :a', ['a' => $this->artisan])))->link());
        self::assertResponseRedirects('/espace-client/messages/'.self::CONVERSATION, null, 'fil existant repris');

        $compose = $this->client->request('GET', '/espace-client/messages/nouveau?artisan='.$this->artisan);
        self::assertStringContainsString('question préalable', $compose->filter('.tm-msg-page')->text());
        $this->client->submit($compose->selectButton('Envoyer')->form(), ['message' => 'Intervenez-vous aussi le samedi ?']);
        self::assertResponseStatusCodeSame(303);
        self::assertSame(2, DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM messaging.conversation')));
    }
}
