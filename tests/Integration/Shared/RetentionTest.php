<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Administration\Application\Retention\PurgeAuditLog;
use App\Administration\Infrastructure\Audit\DbalAuditTrail;
use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\Content\Application\Retention\PurgeContactMessages;
use App\Identity\Application\Command\RegisterUser;
use App\Identity\Application\Privacy\PurgeIdentityRetention;
use App\Messaging\Application\Conversation\StartConversation;
use App\Messaging\Application\EventHandler\ScrubMessagesOfErasedAccount;
use App\QuoteRequest\Application\Dispatch\DispatchQuoteRequest;
use App\QuoteRequest\Application\Retention\ArchiveOldRequests;
use App\QuoteRequest\Application\Retention\RequestArchive;
use App\QuoteRequest\Application\Submission\SubmitQuoteRequest;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Event\AccountErased;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** T-808a — CDC §11 : journaux, appareils, consentements, demandes, messages d'un compte effacé, messages de contact. */
final class RetentionTest extends KernelTestCase
{
    use ArtisanDossier;

    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000f8801';
    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a8801';
    private const string REQUEST = '0190f5e4-0000-7000-8000-0000000f8802';
    private const string CONVERSATION = '0190f5e4-0000-7000-8000-0000000f8803';

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $this->connection = $connection;
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    private function rows(string $sql): int
    {
        return DbalValue::int($this->connection->fetchOne($sql));
    }

    public function testAuditDevicesAndConsentsArePurgedAndChainStaysValid(): void
    {
        $audit = self::getContainer()->get(AuditTrail::class);
        \assert($audit instanceof DbalAuditTrail);
        foreach (['-26 months', '-14 months', '-2 days'] as $i => $ago) {
            $audit->record(new AuditRecord('0190f5e4-0000-7000-8000-00000000880'.$i, new \DateTimeImmutable($ago), 'system', null, 'test.retention'));
        }
        $this->dispatchCommand(new PurgeAuditLog());
        self::assertSame(1, $this->rows("SELECT count(*) FROM admin.audit_log WHERE action = 'test.retention'"));
        self::assertSame(1, $this->rows('SELECT count(*) FROM admin.audit_anchor'));
        self::assertNull($audit->verifyChain(), 'chaîne valide depuis le point d’ancrage');
        try {
            $this->connection->transactional(static fn (Connection $c) => $c->executeStatement("DELETE FROM admin.audit_log WHERE action = 'test.retention'")); // point de sauvegarde : l'échec n'annule pas la transaction du test
            self::fail('suppression hors rétention refusée attendue');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertStringContainsString('ajout seul', $e->getMessage());
        }

        $this->dossierDispatch(new RegisterUser(self::CLIENT, 'camille.808@exemple.test', 'cheval agrafe batterie', 'client', 'Camille', 'Fictive'));
        $this->connection->executeStatement("INSERT INTO identity.session_device (id, user_id, device_hash, description, first_seen_at, last_seen_at) VALUES (gen_random_uuid(), :u, repeat('b', 64), 'Ancien (fictif)', now() - interval '20 months', now() - interval '13 months'), (gen_random_uuid(), :u, repeat('c', 64), 'Récent (fictif)', now() - interval '2 days', now() - interval '1 day')", ['u' => self::CLIENT]);
        $this->connection->executeStatement("INSERT INTO identity.consent (id, visitor_id, choices, source, policy_version, given_at, expires_at) VALUES (gen_random_uuid(), gen_random_uuid(), '{}', 'accept_all', '1', now() - interval '8 months', now() - interval '2 months'), (gen_random_uuid(), gen_random_uuid(), '{}', 'accept_all', '1', now(), now() + interval '6 months')");
        $this->dispatchCommand(new PurgeIdentityRetention());
        self::assertSame(['Récent (fictif)'], $this->connection->fetchFirstColumn('SELECT description FROM identity.session_device WHERE user_id = :u', ['u' => self::CLIENT]));
        self::assertSame(1, $this->rows('SELECT count(*) FROM identity.consent WHERE expires_at > now()'));
        self::assertSame(0, $this->rows('SELECT count(*) FROM identity.consent WHERE expires_at < now()'));
    }

    public function testRequestsMessagesAndContactsAreArchived(): void
    {
        $this->prepareReferentials();
        $artisan = $this->submittedDossier(200);
        $this->uploadPiece('kbis');
        $this->uploadPiece('insurance_rc');
        $this->dossierDispatch(new ApproveArtisan($artisan, self::ADMIN));
        $this->dossierDispatch(new RegisterUser(self::CLIENT, 'camille.808@exemple.test', 'cheval agrafe batterie', 'client', 'Camille', 'Fictive'));
        $this->connection->executeStatement("UPDATE identity.user_account SET status = 'active', email_verified_at = date_trunc('second', now()) WHERE id IN (:a, :b)", ['a' => self::DOSSIER_USER, 'b' => self::CLIENT]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $job = $this->dossierValue("SELECT id FROM catalog.job WHERE slug = 'plombier'");
        $this->dossierDispatch(new SubmitQuoteRequest(self::REQUEST, self::CLIENT, $job, '76351', '76600', '1 rue Fictive', 'repair', 'Fuite sous l’évier de la cuisine depuis ce matin (fictif).', [], '500_1000', 'week', null, title: 'Fuite sous l’évier'));
        $this->dossierDispatch(new DispatchQuoteRequest(self::REQUEST));
        $this->dossierDispatch(new StartConversation(self::DOSSIER_USER, self::CONVERSATION, '0190f5e4-0000-7000-8000-0000000f8811', self::REQUEST, null, 'Bonjour, je peux passer demain matin (fictif).'));

        // Demande close depuis plus de 3 ans : contenu, adresse effacés.
        $this->connection->executeStatement("UPDATE request.quote_request SET status = 'expired', updated_at = now() - interval '4 years' WHERE id = :r", ['r' => self::REQUEST]);
        $this->dispatchCommand(new ArchiveOldRequests());
        self::assertSame(RequestArchive::PLACEHOLDER, DbalValue::string($this->connection->fetchOne('SELECT description FROM request.quote_request WHERE id = :r', ['r' => self::REQUEST])));
        self::assertSame(0, $this->rows("SELECT count(*) FROM request.request_address WHERE request_id = '".self::REQUEST."'"));
        self::assertNotNull($this->connection->fetchOne('SELECT number FROM request.quote_request WHERE id = :r', ['r' => self::REQUEST]), 'numéro conservé');

        // Compte effacé : ses messages remplacés par une mention.
        $scrub = self::getContainer()->get(ScrubMessagesOfErasedAccount::class);
        \assert($scrub instanceof ScrubMessagesOfErasedAccount);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->wrapInTransaction(static fn () => $scrub(new AccountErased(self::DOSSIER_USER, new \DateTimeImmutable())));
        self::assertStringContainsString('compte de son auteur a été effacé', DbalValue::string($this->connection->fetchOne('SELECT body FROM messaging.message WHERE conversation_id = :c', ['c' => self::CONVERSATION])));

        // Messages de contact de plus de 3 ans.
        $this->connection->executeStatement("INSERT INTO content.contact_message (id, reference, reason, name, email, message, status, created_at) VALUES (gen_random_uuid(), 'CT-OLD', 'question', 'Camille (fictif)', 'old@exemple.test', 'Ancien message fictif', 'new', now() - interval '4 years'), (gen_random_uuid(), 'CT-NEW', 'question', 'Camille (fictif)', 'new@exemple.test', 'Message récent fictif', 'new', now())");
        $this->dispatchCommand(new PurgeContactMessages());
        self::assertSame(['CT-NEW'], $this->connection->fetchFirstColumn("SELECT reference FROM content.contact_message WHERE reference LIKE 'CT-%'"));
    }

    private function dispatchCommand(object $command): void
    {
        $bus = self::getContainer()->get(\App\Shared\Application\Bus\CommandBus::class);
        \assert($bus instanceof \App\Shared\Application\Bus\CommandBus);
        \assert($command instanceof \App\Shared\Application\Bus\Command);
        $bus->dispatch($command);
    }
}
