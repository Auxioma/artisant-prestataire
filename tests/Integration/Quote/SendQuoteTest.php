<?php

declare(strict_types=1);

namespace App\Tests\Integration\Quote;

use App\Administration\Application\Command\ChangeSetting;
use App\Administration\Infrastructure\Settings\DbalSettingsProvider;
use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\Billing\Application\Credit\CreditLedger;
use App\Identity\Application\Command\RegisterUser;
use App\Quote\Application\Editor\SaveQuoteDraft;
use App\Quote\Application\EventHandler\NotifyQuoteSent;
use App\Quote\Application\Sending\SendQuote;
use App\Quote\Domain\Quote\Event\QuoteStatusChanged;
use App\Quote\Domain\Sending\QuoteSendingRefused;
use App\Quote\Domain\Sending\RefusalReason;
use App\QuoteRequest\Application\Dispatch\DispatchQuoteRequest;
use App\QuoteRequest\Application\Submission\SubmitQuoteRequest;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\ArtisanDossier;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Cache\CacheInterface;

/** T-504 — envoi atomique RG-101 : numéro, PDF figé, débit RG-104, demande complète, refus sans effet, E-12. */
final class SendQuoteTest extends KernelTestCase
{
    use ArtisanDossier;

    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000f4001';
    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a4001';
    private const string REQUEST = '0190f5e4-0000-7000-8000-0000000f4101';
    private const string QUOTE = '0190f5e4-0000-7000-8000-0000000f4201';

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
        $this->dossierDispatch(new RegisterUser(self::CLIENT, 'camille.envoi@exemple.test', 'cheval agrafe batterie', 'client', 'Camille', 'Envoi'));
        $this->dossierDispatch(new ChangeSetting('quotes.max_per_request', 1, self::ADMIN, 'Test RG-101 : dernière place'));
        $cache = self::getContainer()->get('cache.settings');
        $settings = self::getContainer()->get(DbalSettingsProvider::class);
        \assert($cache instanceof CacheInterface && $settings instanceof DbalSettingsProvider);
        $cache->delete(DbalSettingsProvider::CACHE_KEY);
        $settings->reset();
        $job = $this->dossierValue("SELECT id FROM catalog.job WHERE slug = 'plombier'");
        $this->dossierDispatch(new SubmitQuoteRequest(self::REQUEST, self::CLIENT, $job, '76351', '76600', '1 rue Fictive', 'repair', 'Fuite sous l’évier de la cuisine depuis ce matin (fictif).', [], null, 'week', null, title: 'Fuite sous l’évier'));
        $this->dossierDispatch(new DispatchQuoteRequest(self::REQUEST));
        $this->dossierDispatch(new SaveQuoteDraft(self::DOSSIER_USER, self::REQUEST, self::QUOTE, [
            'subject' => 'Réparation de la fuite',
            'lines' => [
                ['type' => 'labour', 'label' => 'Recherche de fuite', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '90', 'vat' => '1000'],
                ['type' => 'travel', 'label' => 'Déplacement', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '0', 'vat' => '2000'],
            ],
        ]));
    }

    public function testRefusedWithoutCreditThenSentAtomically(): void
    {
        // (f) aucun crédit : refus, rien n'a changé.
        $this->assertRefused(RefusalReason::NoCredit);
        self::assertSame(['draft', null], array_values((array) $this->connection->fetchAssociative('SELECT status, number FROM quote.quote WHERE id = :id', ['id' => self::QUOTE])));
        self::assertSame('0', $this->dossierValue('SELECT count(*)::text FROM billing.credit_ledger_entry WHERE amount < 0'));

        $ledger = self::getContainer()->get(CreditLedger::class);
        \assert($ledger instanceof CreditLedger);
        $ledger->grantFree('0190f5e4-0000-7000-8000-0000000f4301', $this->artisan, self::DOSSIER_USER, 2, null, new \DateTimeImmutable());

        $this->dossierDispatch(new SendQuote(self::DOSSIER_USER, self::REQUEST));
        $year = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'))->format('Y');
        $row = $this->connection->fetchAssociative('SELECT q.status, q.number, v.sent_at IS NOT NULL AS sent, v.pdf_sha256 IS NOT NULL AS pdf FROM quote.quote q JOIN quote.quote_version v ON v.quote_id = q.id WHERE q.id = :id', ['id' => self::QUOTE]);
        self::assertSame(['status' => 'sent', 'number' => 'DEV-'.$year.'-0001', 'sent' => true, 'pdf' => true], $row);
        self::assertSame(['free', '-1'], array_values((array) $this->connection->fetchAssociative("SELECT bucket, amount::text FROM billing.credit_ledger_entry WHERE type = 'quote_debit' AND reference_id = :q", ['q' => self::QUOTE])));
        self::assertSame(1, DbalValue::int($this->connection->fetchOne('SELECT free FROM billing.credit_balance WHERE artisan_id = :a', ['a' => $this->artisan])), 'RG-104 : gratuit d’abord');
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        self::assertSame(['full', 1], array_values((array) $this->connection->fetchAssociative('SELECT status, active_quotes_count FROM request.quote_request WHERE id = :id', ['id' => self::REQUEST])), 'RG-070 : dernière place');
        self::assertSame('1', $this->dossierValue("SELECT count(*)::text FROM admin.audit_log WHERE action = 'quote.sent'"));

        // (a)/(b) puis (d) : un second envoi ne débite rien.
        $this->assertRefused(RefusalReason::RequestFull);
        self::assertSame('1', $this->dossierValue("SELECT count(*)::text FROM billing.credit_ledger_entry WHERE type = 'quote_debit'"));

        $notify = self::getContainer()->get(NotifyQuoteSent::class);
        \assert($notify instanceof NotifyQuoteSent);
        $notify(new QuoteStatusChanged(self::QUOTE, self::REQUEST, $this->artisan, 'draft', 'sent', null, new \DateTimeImmutable()));
        self::assertSame('1', $this->dossierValue("SELECT count(*)::text FROM notification.delivery WHERE code = 'E-12' AND channel = 'email'"));
    }

    private function assertRefused(RefusalReason $reason): void
    {
        try {
            $this->dossierDispatch(new SendQuote(self::DOSSIER_USER, self::REQUEST));
            self::fail('envoi refusé attendu : '.$reason->value);
        } catch (QuoteSendingRefused $e) {
            self::assertSame($reason, $e->reason, $e->getMessage());
        }
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }
}
