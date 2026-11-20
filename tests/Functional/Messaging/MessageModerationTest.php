<?php

declare(strict_types=1);

namespace App\Tests\Functional\Messaging;

use App\Administration\Infrastructure\Settings\DbalSettingsProvider;
use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\Billing\Application\Credit\CreditLedger;
use App\Identity\Application\Command\RegisterUser;
use App\Messaging\Application\Conversation\ReportMessage;
use App\Messaging\Application\Conversation\SendMessage;
use App\Messaging\Application\Conversation\StartConversation;
use App\Messaging\Application\Query\ConversationReader;
use App\Quote\Application\Editor\SaveQuoteDraft;
use App\Quote\Application\Sending\SendQuote;
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
use Symfony\Contracts\Cache\CacheInterface;

/**
 * BO05 « Messages signalés » (T-701d2) : file avec contexte, lecture journalisée de l'original scellé, classement,
 * retrait (motif obligatoire) affiché « Message retiré par la modération » aux deux participants.
 */
final class MessageModerationTest extends WebTestCase
{
    use AdminSession;
    use ArtisanDossier;

    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000fd701';
    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000ad701';
    private const string REQUEST = '0190f5e4-0000-7000-8000-0000000fd702';
    private const string QUOTE = '0190f5e4-0000-7000-8000-0000000fd703';
    private const string CONVERSATION = '0190f5e4-0000-7000-8000-0000000fd704';

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
        $this->dossierDispatch(new RegisterUser(self::CLIENT, 'camille.bo05@exemple.test', 'cheval agrafe batterie', 'client', 'Camille', 'Fictive'));
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
        $ledger->grantFree('0190f5e4-0000-7000-8000-0000000fd705', $this->artisan, self::DOSSIER_USER, 3, null, new \DateTimeImmutable());
        $this->dossierDispatch(new SendQuote(self::DOSSIER_USER, self::REQUEST));
        $this->dossierDispatch(new StartConversation(self::DOSSIER_USER, self::CONVERSATION, '0190f5e4-0000-7000-8000-0000000fd711', self::REQUEST, null, 'Bonjour, joignable au 06 12 34 56 78. Le devis est envoyé.'));
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $message = DbalValue::string($this->connection->fetchOne('SELECT id FROM messaging.message'));
        $this->dossierDispatch(new ReportMessage(self::CLIENT, self::CONVERSATION, $message, 'contact'));
        $this->adminSession($this->client, self::ADMIN, 'moderator');
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    public function testOriginalIsTracedAndMessageRemoved(): void
    {
        $page = $this->client->request('GET', '/admin/moderation/messages');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Messages signalés (1)', $page->filter('.tm-admin-tabs')->text());
        self::assertStringContainsString('Contournement de la plateforme', $page->filter('.tm-moderation__detail')->text());
        self::assertStringContainsString('signalé par Camille Fictive', $page->filter('.tm-moderation__detail')->text());
        self::assertStringNotContainsString('06 12 34 56 78', $page->filter('.tm-moderation__detail')->text());

        $this->client->submit($page->selectButton('Voir l’original')->form());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('06 12 34 56 78', $this->client->getCrawler()->filter('.tm-moderation__original')->text());
        self::assertSame(1, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM admin.audit_log WHERE action = 'message.original_read'")));

        $page = $this->client->request('GET', '/admin/moderation/messages');
        $this->client->submit($page->selectButton('Retirer le message')->form());
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertStringContainsString('Choisissez le motif du retrait.', (string) $this->client->getResponse()->getContent());
        self::assertNull($this->connection->fetchOne('SELECT moderation_decision FROM messaging.message'));

        $page = $this->client->request('GET', '/admin/moderation/messages');
        $this->client->request('POST', (string) $page->filter('.tm-admin-decision')->attr('action'), ['_token' => (string) $page->filter('.tm-admin-decision input[name="_token"]')->attr('value'), 'decision' => 'remove', 'reason' => 'contact']);
        self::assertResponseRedirects('/admin/moderation/messages', 303);
        self::assertStringContainsString('Messages signalés (0)', $this->client->followRedirect()->filter('.tm-admin-tabs')->text());
        self::assertSame('removed', $this->connection->fetchOne('SELECT moderation_decision FROM messaging.message'));
        self::assertStringContainsString('Bonjour', DbalValue::string($this->connection->fetchOne('SELECT body FROM messaging.message')), 'texte conservé (preuve)');

        $reader = self::getContainer()->get(ConversationReader::class);
        \assert($reader instanceof ConversationReader);
        $thread = $reader->thread(self::CLIENT, self::CONVERSATION);
        self::assertNotNull($thread);
        self::assertTrue($thread->messages[0]->removed);
        self::assertSame('', $thread->messages[0]->body);
        self::assertSame('Message retiré par la modération', $reader->list(self::CLIENT, 'all', '', 1, 20)['rows'][0]->lastBody);
    }

    public function testDismissKeepsTheMessage(): void
    {
        $page = $this->client->request('GET', '/admin/moderation/messages');
        $this->client->submit($page->selectButton('Classer sans suite')->form());
        self::assertResponseRedirects('/admin/moderation/messages', 303);
        self::assertSame('dismissed', $this->connection->fetchOne('SELECT moderation_decision FROM messaging.message'));
        $reader = self::getContainer()->get(ConversationReader::class);
        \assert($reader instanceof ConversationReader);
        self::assertFalse($reader->thread(self::CLIENT, self::CONVERSATION)?->messages[0]->removed);
    }

    /** T-706b — BO05.3 : expressions de contournement éditables ; message au-delà du seuil signalé automatiquement. */
    public function testWorkaroundKeywordsAndAutomaticFlag(): void
    {
        $page = $this->client->request('GET', '/admin/moderation/regles');
        self::assertStringContainsString('whatsapp', (string) $page->filter('#contact_keywords')->text());
        $this->client->submit($page->selectButton('Enregistrer les expressions')->form(), ['contact_keywords' => "whatsapp\nAppelez-moi directement\n"]);
        self::assertResponseRedirects('/admin/moderation/regles');
        $cache = self::getContainer()->get('cache.settings');
        $settings = self::getContainer()->get(DbalSettingsProvider::class);
        \assert($cache instanceof CacheInterface && $settings instanceof DbalSettingsProvider);
        $cache->delete(DbalSettingsProvider::CACHE_KEY);
        $settings->reset();

        // Message de l'artisan avant acceptation : coordonnées (30) + contournement (30) ≥ seuil 50 → signalé.
        $this->dossierDispatch(new SendMessage(self::DOSSIER_USER, self::CONVERSATION, '0190f5e4-0000-7000-8000-0000000fd712', 'Écrivez-moi sur WhatsApp au 06 12 34 56 78, appelez-moi directement.'));
        self::assertSame('auto:60', DbalValue::string($this->connection->fetchOne("SELECT report_reason FROM messaging.message WHERE id = '0190f5e4-0000-7000-8000-0000000fd712'")));
        // Message ordinaire : non signalé.
        $this->dossierDispatch(new SendMessage(self::DOSSIER_USER, self::CONVERSATION, '0190f5e4-0000-7000-8000-0000000fd713', 'Je passe mardi matin comme convenu.'));
        self::assertNull($this->connection->fetchOne("SELECT reported_at FROM messaging.message WHERE id = '0190f5e4-0000-7000-8000-0000000fd713'"));

        $page = $this->client->request('GET', '/admin/moderation/messages?element=0190f5e4-0000-7000-8000-0000000fd712');
        self::assertStringContainsString('Messages signalés (2)', $page->filter('.tm-admin-tabs')->text());
        self::assertStringContainsString('Détection automatique (score 60/100)', $page->filter('.tm-moderation__detail')->text());
        self::assertStringContainsString('signalé par Détection automatique', $page->filter('.tm-moderation__detail')->text());
    }
}
