<?php

declare(strict_types=1);

namespace App\Tests\Functional\Messaging;

use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\Billing\Application\Credit\CreditLedger;
use App\Identity\Application\Command\RegisterUser;
use App\Messaging\Application\Conversation\StartConversation;
use App\Messaging\Application\Query\ConversationReader;
use App\Quote\Application\Editor\SaveQuoteDraft;
use App\Quote\Application\Sending\SendQuote;
use App\QuoteRequest\Application\Dispatch\DispatchQuoteRequest;
use App\QuoteRequest\Application\Submission\SubmitQuoteRequest;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Messaging\ArtisanResponseTime;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * PR07 (Figma 232:13464) : onglets Clients / Prospects et compteurs, encart client, note privée, réponses types,
 * entrée « Écrire au client » depuis PR03, délai moyen de réponse affiché sur P10.
 */
final class ProMessagesPageTest extends WebTestCase
{
    use ArtisanDossier;

    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000fc701';
    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000ac701';
    private const string REQUEST = '0190f5e4-0000-7000-8000-0000000fc702';
    private const string QUOTE = '0190f5e4-0000-7000-8000-0000000fc703';
    private const string CONVERSATION = '0190f5e4-0000-7000-8000-0000000fc704';

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
        $this->dossierDispatch(new RegisterUser(self::CLIENT, 'camille.pr07@exemple.test', 'cheval agrafe batterie', 'client', 'Camille', 'Fictive'));
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
        $ledger->grantFree('0190f5e4-0000-7000-8000-0000000fc705', $this->artisan, self::DOSSIER_USER, 3, null, new \DateTimeImmutable());
        $this->dossierDispatch(new SendQuote(self::DOSSIER_USER, self::REQUEST));
        $this->dossierDispatch(new StartConversation(self::DOSSIER_USER, self::CONVERSATION, '0190f5e4-0000-7000-8000-0000000fc711', self::REQUEST, null, 'Bonjour, joignable au 06 12 34 56 78. Le devis est envoyé.'));
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $crawler = $this->client->request('GET', '/connexion');
        $this->client->request('POST', '/connexion', ['_username' => 'dominique.verif@exemple.test', '_password' => 'cheval agrafe batterie', '_csrf_token' => (string) $crawler->filter('input[name="_csrf_token"]')->attr('value')]);
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    public function testTabsClientCardNoteAndQuickReplies(): void
    {
        $page = $this->client->request('GET', '/espace-pro/messages');
        self::assertResponseIsSuccessful();
        $tabs = $page->filter('.tm-msg-list__tabs a')->each(static fn ($a): string => trim(preg_replace('/\s+/', ' ', $a->text()) ?? ''));
        self::assertSame(['Toutes (1)', 'Non lues (0)', 'Clients (0)', 'Prospects (1)', 'Archivées (0)'], $tabs);
        self::assertCount(0, $this->client->request('GET', '/espace-pro/messages?filtre=clients')->filter('.tm-msg-row'));
        self::assertCount(1, $this->client->request('GET', '/espace-pro/messages?filtre=prospects')->filter('.tm-msg-row'));

        $thread = $this->client->request('GET', '/espace-pro/messages/'.self::CONVERSATION);
        self::assertResponseIsSuccessful();
        self::assertSame('Camille', $thread->filter('.tm-msg-aside__name')->text(), 'prénom seul (RG-112)');
        self::assertSame('Prospect', trim($thread->filter('.tm-msg-aside__status')->text()));
        self::assertSame('/espace-pro/demandes/'.self::REQUEST, $thread->filter('.tm-msg-aside__links a')->first()->attr('href'));

        $this->client->submit($thread->selectButton('Enregistrer la note')->form(), ['note' => 'RDV mardi 10 h (note fictive).']);
        self::assertResponseRedirects('/espace-pro/messages/'.self::CONVERSATION);
        $thread = $this->client->followRedirect();
        self::assertSame('RDV mardi 10 h (note fictive).', $thread->filter('.tm-msg-note__text')->text());
        self::assertNull($this->connection->fetchOne('SELECT note FROM messaging.participant WHERE user_id = :u', ['u' => self::CLIENT]), 'note visible de l’artisan seul');

        self::assertCount(0, $thread->filter('.tm-msg-quick'));
        $this->client->submit($thread->filter('.tm-msg-replies__form')->form(), ['title' => 'Disponibilités', 'body' => 'Je peux passer cette semaine (texte fictif).']);
        $thread = $this->client->followRedirect();
        self::assertSame('Disponibilités', $thread->filter('.tm-msg-quick button')->text());
        self::assertSame('Je peux passer cette semaine (texte fictif).', $thread->filter('.tm-msg-quick button')->attr('data-quick-reply-text-param'));
        $this->client->submit($thread->filter('.tm-msg-replies li form')->form());
        self::assertCount(0, $this->client->followRedirect()->filter('.tm-msg-quick'));

        $this->connection->executeStatement("UPDATE quote.quote SET status = 'accepted' WHERE id = :q", ['q' => self::QUOTE]);
        $page = $this->client->request('GET', '/espace-pro/messages/'.self::CONVERSATION);
        self::assertSame('Client actif', trim($page->filter('.tm-msg-aside__status')->text()));
        self::assertCount(1, $this->client->request('GET', '/espace-pro/messages?filtre=clients')->filter('.tm-msg-row'));
    }

    public function testWriteToClientFromRequestAndResponseTime(): void
    {
        $detail = $this->client->request('GET', '/espace-pro/demandes/'.self::REQUEST);
        $this->client->click($detail->selectLink('Écrire au client')->link());
        self::assertResponseRedirects('/espace-pro/messages/'.self::CONVERSATION, null, 'fil existant repris');

        $reader = self::getContainer()->get(ConversationReader::class);
        \assert($reader instanceof ConversationReader);
        $since = new \DateTimeImmutable('-1 day');
        self::assertNull($reader->averageResponseMinutes($this->artisan, $since, 1), 'le client n’a pas encore écrit');
        // Premier message du client il y a 3 h, réponse de l'artisan 2 h plus tard.
        $this->connection->executeStatement(
            "INSERT INTO messaging.message (id, conversation_id, sender_user_id, body, created_at) VALUES ('0190f5e4-0000-7000-8000-0000000fc721', :c, :client, 'Question (fictive)', now() - interval '3 hours'), ('0190f5e4-0000-7000-8000-0000000fc722', :c, :pro, 'Réponse (fictive)', now() - interval '1 hour')",
            ['c' => self::CONVERSATION, 'client' => self::CLIENT, 'pro' => self::DOSSIER_USER],
        );
        self::assertSame(120, $reader->averageResponseMinutes($this->artisan, $since, 1));
        self::assertNull($reader->averageResponseMinutes($this->artisan, $since, 3), 'échantillon insuffisant');
        $queries = self::getContainer()->get(QueryBus::class);
        \assert($queries instanceof QueryBus);
        self::assertNull($queries->ask(new ArtisanResponseTime($this->artisan)));
    }
}
