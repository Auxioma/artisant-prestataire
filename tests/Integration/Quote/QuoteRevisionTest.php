<?php

declare(strict_types=1);

namespace App\Tests\Integration\Quote;

use App\Administration\Application\Command\ChangeSetting;
use App\Administration\Infrastructure\Settings\DbalSettingsProvider;
use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\Billing\Application\Credit\CreditLedger;
use App\Identity\Application\Command\RegisterUser;
use App\Quote\Application\Editor\SaveQuoteDraft;
use App\Quote\Application\EventHandler\NotifyQuoteRevised;
use App\Quote\Application\EventHandler\NotifyQuoteSent;
use App\Quote\Application\EventHandler\RenotifyFreedSlot;
use App\Quote\Application\Expiry\ExpireQuotes;
use App\Quote\Application\Revision\RemindClient;
use App\Quote\Application\Revision\ReviseQuote;
use App\Quote\Application\Revision\WithdrawQuote;
use App\Quote\Application\Sending\SendQuote;
use App\Quote\Domain\Quote\Event\QuoteReminded;
use App\Quote\Domain\Quote\Event\QuoteRevised;
use App\Quote\Domain\Quote\Event\QuoteStatusChanged;
use App\Quote\Domain\Quote\QuoteRuleViolation;
use App\QuoteRequest\Application\Dispatch\DispatchQuoteRequest;
use App\QuoteRequest\Application\Dispatch\RenotifyRequest;
use App\QuoteRequest\Application\Submission\SubmitQuoteRequest;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * RG-102 révision (nouvelle version figée, sans crédit ni place, E-12b) ; RG-103 retrait avant consultation (place
 * libérée, demande complète rouverte, crédit non restitué) ; P-09 relance des destinataires (hors exclus) ; relance du
 * client (P-11, E-12 en rappel) et expiration de la validité (RG-121, place libérée).
 */
final class QuoteRevisionTest extends KernelTestCase
{
    use ArtisanDossier;

    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000f6001';
    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a6001';
    private const string REQUEST = '0190f5e4-0000-7000-8000-0000000f6101';
    private const string QUOTE = '0190f5e4-0000-7000-8000-0000000f6201';
    private const array LINES = [
        ['type' => 'labour', 'label' => 'Recherche de fuite', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '90', 'vat' => '1000'],
        ['type' => 'travel', 'label' => 'Déplacement', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '0', 'vat' => '2000'],
    ];

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
        $connection->executeStatement("UPDATE identity.user_account SET status = 'active', email_verified_at = date_trunc('second', now()) WHERE id = :id", ['id' => self::DOSSIER_USER]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->dossierDispatch(new RegisterUser(self::CLIENT, 'camille.revision@exemple.test', 'cheval agrafe batterie', 'client', 'Camille', 'Révision'));
        $this->dossierDispatch(new ChangeSetting('quotes.max_per_request', 1, self::ADMIN, 'Test RG-103 : demande complète rouverte'));
        $cache = self::getContainer()->get('cache.settings');
        $settings = self::getContainer()->get(DbalSettingsProvider::class);
        \assert($cache instanceof CacheInterface && $settings instanceof DbalSettingsProvider);
        $cache->delete(DbalSettingsProvider::CACHE_KEY);
        $settings->reset();
        $job = $this->dossierValue("SELECT id FROM catalog.job WHERE slug = 'plombier'");
        $this->dossierDispatch(new SubmitQuoteRequest(self::REQUEST, self::CLIENT, $job, '76351', '76600', '1 rue Fictive', 'repair', 'Fuite sous l’évier de la cuisine depuis ce matin (fictif).', [], null, 'week', null, title: 'Fuite sous l’évier'));
        $this->dossierDispatch(new DispatchQuoteRequest(self::REQUEST));
        $this->dossierDispatch(new SaveQuoteDraft(self::DOSSIER_USER, self::REQUEST, self::QUOTE, ['subject' => 'Réparation de la fuite', 'lines' => self::LINES]));
        $ledger = self::getContainer()->get(CreditLedger::class);
        \assert($ledger instanceof CreditLedger);
        $ledger->grantFree('0190f5e4-0000-7000-8000-0000000f6301', $this->artisan, self::DOSSIER_USER, 2, null, new \DateTimeImmutable());
        $this->dossierDispatch(new SendQuote(self::DOSSIER_USER, self::REQUEST));
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    public function testRevisionIsAFrozenFreeNewVersion(): void
    {
        try {
            $this->dossierDispatch(new ReviseQuote(self::DOSSIER_USER, self::REQUEST, ['subject' => 'Réparation', 'lines' => [self::LINES[0]]]));
            self::fail('frais de déplacement manquants : révision refusée');
        } catch (QuoteRuleViolation $e) {
            self::assertStringContainsString('Il manque des informations obligatoires : frais de déplacement', $e->getMessage());
        }

        $lines = self::LINES;
        $lines[0]['unit_price'] = '120';
        $this->dossierDispatch(new ReviseQuote(self::DOSSIER_USER, self::REQUEST, ['subject' => 'Réparation de la fuite et du joint', 'lines' => $lines]));
        $versions = $this->connection->fetchAllAssociative('SELECT version, total_ttc_cents, sent_at IS NOT NULL AS sent, pdf_sha256 IS NOT NULL AS pdf FROM quote.quote_version WHERE quote_id = :q ORDER BY version', ['q' => self::QUOTE]);
        self::assertSame([[1, 9900, true, true], [2, 13200, true, true]], array_map(static fn (array $v): array => [DbalValue::int($v['version']), DbalValue::int($v['total_ttc_cents']), $v['sent'], $v['pdf']], $versions), 'v1 intacte, v2 figée');
        self::assertSame(['sent', 1], array_values((array) $this->connection->fetchAssociative('SELECT q.status, r.active_quotes_count FROM quote.quote q JOIN request.quote_request r ON r.id = q.request_id WHERE q.id = :q', ['q' => self::QUOTE])));
        self::assertSame('1', $this->dossierValue("SELECT count(*)::text FROM billing.credit_ledger_entry WHERE type = 'quote_debit'"), 'RG-102 : aucun crédit');
        self::assertSame('1', $this->dossierValue("SELECT count(*)::text FROM admin.audit_log WHERE action = 'quote.revised'"));

        $notify = self::getContainer()->get(NotifyQuoteRevised::class);
        \assert($notify instanceof NotifyQuoteRevised);
        $notify(new QuoteRevised(self::QUOTE, self::REQUEST, $this->artisan, 2, new \DateTimeImmutable()));
        self::assertSame("Devis mis à jour par Atelier Fictif (version 2) : 132,00\u{a0}€ TTC (demande ".$this->dossierValue("SELECT number FROM request.quote_request WHERE id = '".self::REQUEST."'").').', $this->dossierValue("SELECT body FROM notification.in_app WHERE code = 'E-12b'"));
        self::assertSame('1', $this->dossierValue("SELECT count(*)::text FROM notification.delivery WHERE code = 'E-12b' AND channel = 'email'"));
    }

    public function testWithdrawalFreesThePlaceButKeepsTheCredit(): void
    {
        self::assertSame('full', $this->dossierValue("SELECT status FROM request.quote_request WHERE id = '".self::REQUEST."'"));
        $this->dossierDispatch(new WithdrawQuote(self::DOSSIER_USER, self::REQUEST));
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        self::assertSame('withdrawn', $this->dossierValue("SELECT status FROM quote.quote WHERE id = '".self::QUOTE."'"));
        self::assertSame(['open', 0, 'quote_withdrawn'], array_values((array) $this->connection->fetchAssociative('SELECT status, active_quotes_count, status_reason FROM request.quote_request WHERE id = :id', ['id' => self::REQUEST])), 'P-09 : la demande complète rouvre');
        self::assertSame(1, DbalValue::int($this->connection->fetchOne('SELECT free FROM billing.credit_balance WHERE artisan_id = :a', ['a' => $this->artisan])), 'crédit non restitué');

        try {
            $this->dossierDispatch(new ReviseQuote(self::DOSSIER_USER, self::REQUEST, ['subject' => 'X', 'lines' => self::LINES]));
            self::fail('un devis retiré ne se révise plus');
        } catch (QuoteRuleViolation $e) {
            self::assertSame('Ce devis ne peut plus être modifié.', $e->getMessage());
        }

        // P-09 : l'artisan qui s'est retiré est exclu de la relance ; sans exclusion, il retrouverait la demande au digest.
        $this->connection->executeStatement('UPDATE request.request_dispatch SET notified_at = dispatched_at WHERE request_id = :r', ['r' => self::REQUEST]);
        $renotify = self::getContainer()->get(RenotifyFreedSlot::class);
        \assert($renotify instanceof RenotifyFreedSlot);
        $renotify(new QuoteStatusChanged(self::QUOTE, self::REQUEST, $this->artisan, 'sent', 'withdrawn', null, new \DateTimeImmutable()));
        self::assertSame('0', $this->dossierValue("SELECT count(*)::text FROM request.request_dispatch WHERE request_id = '".self::REQUEST."' AND notified_at IS NULL"));
        $this->dossierDispatch(new RenotifyRequest(self::REQUEST, []));
        self::assertSame('1', $this->dossierValue("SELECT count(*)::text FROM request.request_dispatch WHERE request_id = '".self::REQUEST."' AND notified_at IS NULL"));
        self::assertSame('2', $this->dossierValue("SELECT count(*)::text FROM admin.audit_log WHERE action = 'request.renotified'"));
    }

    public function testViewedQuoteCannotBeWithdrawn(): void
    {
        $this->connection->executeStatement("UPDATE quote.quote SET status = 'viewed', viewed_at = now() WHERE id = :q", ['q' => self::QUOTE]);
        try {
            $this->dossierDispatch(new WithdrawQuote(self::DOSSIER_USER, self::REQUEST));
            self::fail('retrait refusé après consultation');
        } catch (QuoteRuleViolation $e) {
            self::assertSame('Un devis consulté par le client ne peut plus être retiré.', $e->getMessage());
        }
        self::assertSame('viewed', $this->dossierValue("SELECT status FROM quote.quote WHERE id = '".self::QUOTE."'"));
    }

    public function testReminderWaitsForTheDelayAndUsesE12AsAReminder(): void
    {
        try {
            $this->dossierDispatch(new RemindClient(self::DOSSIER_USER, self::REQUEST));
            self::fail('relance avant le délai');
        } catch (QuoteRuleViolation $e) {
            self::assertStringContainsString('Vous pourrez relancer le client à partir du', $e->getMessage());
        }
        self::assertSame('0', $this->dossierValue("SELECT reminder_count::text FROM quote.quote WHERE id = '".self::QUOTE."'"));

        $notify = self::getContainer()->get(NotifyQuoteSent::class);
        \assert($notify instanceof NotifyQuoteSent);
        $notify(new QuoteReminded(self::QUOTE, self::REQUEST, $this->artisan, 1, new \DateTimeImmutable()));
        self::assertStringStartsWith('Rappel : Nouveau devis de Atelier Fictif', $this->dossierValue("SELECT body FROM notification.in_app WHERE code = 'E-12'"));
    }

    public function testExpiredQuoteFreesThePlace(): void
    {
        $this->connection->executeStatement("UPDATE quote.quote SET valid_until = date_trunc('second', now()) - interval '1 hour' WHERE id = :q", ['q' => self::QUOTE]);
        $this->dossierDispatch(new ExpireQuotes());
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        self::assertSame('expired', $this->dossierValue("SELECT status FROM quote.quote WHERE id = '".self::QUOTE."'"));
        self::assertSame(['open', 0, 'quote_expired'], array_values((array) $this->connection->fetchAssociative('SELECT status, active_quotes_count, status_reason FROM request.quote_request WHERE id = :id', ['id' => self::REQUEST])));
        self::assertSame('1', $this->dossierValue("SELECT count(*)::text FROM admin.audit_log WHERE action = 'quote.expired'"));
        $this->dossierDispatch(new ExpireQuotes());
        self::assertSame('1', $this->dossierValue("SELECT count(*)::text FROM admin.audit_log WHERE action = 'quote.expired'"), 'rejouable sans effet');
    }
}
