<?php

declare(strict_types=1);

namespace App\Tests\Integration\Messaging;

use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\Identity\Application\Command\RegisterUser;
use App\Messaging\Application\Conversation\ConversationAction;
use App\Messaging\Application\Conversation\ReportMessage;
use App\Messaging\Application\Conversation\SendMessage;
use App\Messaging\Application\Conversation\StartConversation;
use App\Messaging\Application\EventHandler\NotifyNewMessage;
use App\Messaging\Application\Maintenance\PurgeConversations;
use App\Messaging\Application\Query\ConversationReader;
use App\Messaging\Application\Query\Conversations;
use App\Messaging\Application\Query\ConversationsPage;
use App\Messaging\Application\Query\ConversationThread;
use App\Messaging\Application\Query\OpenConversation;
use App\Messaging\Domain\Conversation\Event\MessagePosted;
use App\Messaging\Domain\Conversation\MessagingRuleViolation;
use App\QuoteRequest\Application\Dispatch\DispatchQuoteRequest;
use App\QuoteRequest\Application\Submission\SubmitQuoteRequest;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\SecretBox;
use App\Shared\Domain\Text\ContactDetector;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * T-701a — messagerie : RG-081 (l'artisan écrit sur une demande reçue, le client à un artisan qui lui a répondu ou en
 * question préalable), RG-080 (coordonnées masquées, original scellé), E-17 regroupé, non-lus, blocage, signalement,
 * vues du client (nom de l'artisan) et de l'artisan (prénom du client, RG-112), purge.
 */
final class MessagingTest extends KernelTestCase
{
    use ArtisanDossier;

    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000fa701';
    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000aa701';
    private const string REQUEST = '0190f5e4-0000-7000-8000-0000000fa702';
    private const string CONVERSATION = '0190f5e4-0000-7000-8000-0000000fa703';

    private Connection $connection;
    private string $artisan;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $this->connection = $connection;
        $this->prepareReferentials();
        $this->artisan = $this->submittedDossier(200);
        $this->uploadPiece('kbis');
        $this->uploadPiece('insurance_rc');
        $this->dossierDispatch(new ApproveArtisan($this->artisan, self::ADMIN));
        $this->dossierDispatch(new RegisterUser(self::CLIENT, 'camille.messages@exemple.test', 'cheval agrafe batterie', 'client', 'Camille', 'Fictive'));
        $connection->executeStatement("UPDATE identity.user_account SET status = 'active', email_verified_at = date_trunc('second', now()) WHERE id IN (:a, :b)", ['a' => self::DOSSIER_USER, 'b' => self::CLIENT]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $job = $this->dossierValue("SELECT id FROM catalog.job WHERE slug = 'plombier'");
        $this->dossierDispatch(new SubmitQuoteRequest(self::REQUEST, self::CLIENT, $job, '76351', '76600', '1 rue Fictive', 'repair', 'Fuite sous l’évier de la cuisine depuis ce matin (fictif).', [], '500_1000', 'week', null, title: 'Fuite sous l’évier'));
        $this->dossierDispatch(new DispatchQuoteRequest(self::REQUEST));
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    private function value(string $sql): string
    {
        $v = $this->connection->fetchOne($sql);

        return \is_bool($v) ? var_export($v, true) : (string) (\is_scalar($v) ? $v : '');
    }

    private function notifyLast(string $recipient): void
    {
        $id = DbalValue::string($this->connection->fetchOne('SELECT id FROM messaging.message ORDER BY created_at DESC, id DESC LIMIT 1'));
        $handler = self::getContainer()->get(NotifyNewMessage::class);
        \assert($handler instanceof NotifyNewMessage);
        $sender = DbalValue::string($this->connection->fetchOne('SELECT sender_user_id FROM messaging.message WHERE id = :id', ['id' => $id]));
        $handler(new MessagePosted(self::CONVERSATION, $id, $sender, $recipient, new \DateTimeImmutable()));
    }

    public function testConversationFromArtisanWithMaskingAndGroupedEmail(): void
    {
        $this->dossierDispatch(new StartConversation(self::DOSSIER_USER, self::CONVERSATION, '0190f5e4-0000-7000-8000-0000000fa711', self::REQUEST, null, 'Bonjour, appelez-moi au 06 12 34 56 78 pour la fuite.'));

        $row = $this->connection->fetchAssociative('SELECT body, original_sealed FROM messaging.message');
        \assert(\is_array($row));
        self::assertStringContainsString(ContactDetector::MASK, DbalValue::string($row['body']));
        $box = self::getContainer()->get(SecretBox::class);
        \assert($box instanceof SecretBox);
        self::assertStringContainsString('06 12 34 56 78', $box->open(DbalValue::string($row['original_sealed'])), 'original lisible par la modération seulement');

        $this->notifyLast(self::CLIENT);
        $this->dossierDispatch(new SendMessage(self::DOSSIER_USER, self::CONVERSATION, '0190f5e4-0000-7000-8000-0000000fa712', 'Je peux passer mardi.'));
        $this->notifyLast(self::CLIENT);
        self::assertSame('1', $this->value("SELECT count(*) FROM notification.delivery WHERE code = 'E-17' AND channel = 'email'"), 'un e-mail par fil et par période');

        $queries = self::getContainer()->get(QueryBus::class);
        \assert($queries instanceof QueryBus);
        $page = $queries->ask(new Conversations(self::CLIENT));
        \assert($page instanceof ConversationsPage);
        self::assertSame([1, 2], [$page->unread, $page->rows[0]->unread]);
        self::assertSame('Je peux passer mardi.', $page->rows[0]->lastBody);
        self::assertNotSame('Camille', $page->rows[0]->counterpartName, 'le client voit le nom de l’artisan');

        $this->dossierDispatch(new SendMessage(self::CLIENT, self::CONVERSATION, '0190f5e4-0000-7000-8000-0000000fa713', 'Parfait, mardi 10 h.'));
        $page = $queries->ask(new Conversations(self::CLIENT));
        \assert($page instanceof ConversationsPage);
        self::assertSame(0, $page->unread, 'répondre vaut lecture');
        $thread = $queries->ask(new OpenConversation(self::DOSSIER_USER, self::CONVERSATION));
        \assert($thread instanceof ConversationThread);
        self::assertSame(['Camille', 'Fuite sous l’évier', 3], [$thread->counterpartName, $thread->requestTitle, \count($thread->messages)], 'RG-112 : prénom seul côté artisan');
        self::assertTrue($thread->messages[0]->readByCounterpart && $thread->messages[0]->masked);
        self::assertNull($queries->ask(new OpenConversation('0190f5e4-0000-7000-8000-0000000ff799', self::CONVERSATION)), 'aucun accès au fil d’autrui');
    }

    public function testClientRulesPreQuestionAndNoMaskingWhenDisabled(): void
    {
        try {
            $this->dossierDispatch(new StartConversation(self::CLIENT, self::CONVERSATION, '0190f5e4-0000-7000-8000-0000000fa721', self::REQUEST, $this->artisan, 'Bonjour ?'));
            self::fail('client écrivant à un artisan sans devis sur la demande');
        } catch (MessagingRuleViolation $e) {
            self::assertStringContainsString('vous ont envoyé un devis', $e->getMessage());
        }
        try {
            $this->dossierDispatch(new StartConversation(self::DOSSIER_USER, self::CONVERSATION, '0190f5e4-0000-7000-8000-0000000fa722', '0190f5e4-0000-7000-8000-0000000ff702', null, 'Démarchage'));
            self::fail('RG-081 contournée');
        } catch (MessagingRuleViolation $e) {
            self::assertStringContainsString('demande que vous avez reçue', $e->getMessage());
        }

        $this->connection->executeStatement("INSERT INTO admin.setting (key, value, type, updated_at) VALUES ('messages.mask_contacts_before_accept', 'false', 'bool', now()) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value");
        $cache = self::getContainer()->get('cache.settings');
        \assert($cache instanceof \Psr\Cache\CacheItemPoolInterface);
        $cache->clear();
        $provider = self::getContainer()->get(\App\Administration\Infrastructure\Settings\DbalSettingsProvider::class);
        \assert($provider instanceof \App\Administration\Infrastructure\Settings\DbalSettingsProvider);
        $provider->reset();
        $this->dossierDispatch(new StartConversation(self::CLIENT, self::CONVERSATION, '0190f5e4-0000-7000-8000-0000000fa723', null, $this->artisan, 'Question préalable : intervenez-vous le samedi ? 06 12 34 56 78'));
        self::assertSame('pre_question|false', $this->value("SELECT c.kind || '|' || (m.original_sealed IS NOT NULL) FROM messaging.conversation c JOIN messaging.message m ON m.conversation_id = c.id"));
        $reader = self::getContainer()->get(ConversationReader::class);
        \assert($reader instanceof ConversationReader);
        self::assertSame(self::CONVERSATION, $reader->idFor(self::CLIENT, null, $this->artisan));
    }

    public function testBlockReportAndPurge(): void
    {
        $this->dossierDispatch(new StartConversation(self::DOSSIER_USER, self::CONVERSATION, '0190f5e4-0000-7000-8000-0000000fa731', self::REQUEST, null, 'Bonjour'));
        try {
            $this->dossierDispatch(new ReportMessage(self::DOSSIER_USER, self::CONVERSATION, '0190f5e4-0000-7000-8000-0000000fa731', 'spam'));
            self::fail('auteur signalant son propre message');
        } catch (MessagingRuleViolation) {
            self::addToAssertionCount(1);
        }
        $this->dossierDispatch(new ReportMessage(self::CLIENT, self::CONVERSATION, '0190f5e4-0000-7000-8000-0000000fa731', 'spam'));
        self::assertSame('spam', $this->value('SELECT report_reason FROM messaging.message'));

        $this->dossierDispatch(new ConversationAction(self::CLIENT, self::CONVERSATION, ConversationAction::BLOCK));
        try {
            $this->dossierDispatch(new SendMessage(self::DOSSIER_USER, self::CONVERSATION, '0190f5e4-0000-7000-8000-0000000fa732', 'Insistance'));
            self::fail('message dans un fil bloqué');
        } catch (MessagingRuleViolation $e) {
            self::assertStringContainsString('bloquée', $e->getMessage());
        }

        $this->connection->executeStatement("UPDATE messaging.conversation SET last_message_at = now() - interval '4 years'");
        $this->dossierDispatch(new PurgeConversations());
        self::assertSame('0', $this->value('SELECT count(*) FROM messaging.message'));
    }
}
