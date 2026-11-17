<?php

declare(strict_types=1);

namespace App\Tests\Functional\Quote;

use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\Billing\Application\Credit\CreditLedger;
use App\Identity\Application\Command\RegisterUser;
use App\Quote\Application\Editor\SaveQuoteDraft;
use App\Quote\Application\EventHandler\CloseQuotesWithRequest;
use App\Quote\Application\Sending\SendQuote;
use App\QuoteRequest\Application\Dispatch\DispatchQuoteRequest;
use App\QuoteRequest\Application\Submission\SubmitQuoteRequest;
use App\QuoteRequest\Domain\Request\Event\QuoteRequestStatusChanged;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\AdminSession;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * BO04 (Figma 186:1669) : onglet « Devis » (liste, PDF journalisé), suppression pour fraude (motif obligatoire, devis
 * annulés, crédit restitué une seule fois, Q2) ; clôture simple : devis annulés sans restitution.
 */
final class QuotesAdminTest extends WebTestCase
{
    use AdminSession;
    use ArtisanDossier;

    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000fb001';
    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000ab001';
    private const string REQUEST = '0190f5e4-0000-7000-8000-0000000fb101';
    private const string QUOTE = '0190f5e4-0000-7000-8000-0000000fb201';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $this->prepareReferentials();
        $artisan = $this->submittedDossier(200);
        $this->uploadPiece('kbis');
        $this->uploadPiece('insurance_rc');
        $this->dossierDispatch(new ApproveArtisan($artisan, self::ADMIN));
        $this->dossierDispatch(new RegisterUser(self::CLIENT, 'camille.bo04@exemple.test', 'cheval agrafe batterie', 'client', 'Camille', 'Liste'));
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
        $ledger->grantFree('0190f5e4-0000-7000-8000-0000000fb301', $artisan, self::DOSSIER_USER, 1, null, new \DateTimeImmutable());
        $this->dossierDispatch(new SendQuote(self::DOSSIER_USER, self::REQUEST));
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $this->adminSession($this->client, self::ADMIN, 'super_admin');
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    public function testQuotesTabAndPdf(): void
    {
        $crawler = $this->client->request('GET', '/admin/devis');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Devis (1)', $crawler->filter('.tm-admin-tabs')->text());
        $row = $crawler->filter('tbody tr')->first()->text();
        self::assertStringContainsString('Atelier Fictif', $row);
        self::assertStringContainsString("99,00\u{a0}€", $row);
        self::assertStringContainsString('Envoyé', $row);
        self::assertCount(0, $this->client->request('GET', '/admin/devis?statut=accepted')->filter('tbody tr a.tm-icon-link'));

        $this->client->request('GET', '/admin/devis/'.self::QUOTE.'/pdf');
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertSame('1', $this->dossierValue("SELECT count(*)::text FROM admin.audit_log WHERE action = 'quote.pdf_viewed'"));
    }

    public function testFraudDeletionRefundsCreditsOnce(): void
    {
        $crawler = $this->client->request('GET', '/admin/demandes?demande='.self::REQUEST);
        $form = $crawler->filter('textarea#fraud_comment')->closest('form')?->filter('button[value="delete"]')->form();
        self::assertNotNull($form);
        $this->client->submit($form, ['comment' => '']);
        self::assertStringContainsString('Indiquez le motif', $this->client->followRedirect()->filter('.tm-toasts')->text());
        self::assertSame('open', $this->dossierValue("SELECT status FROM request.quote_request WHERE id = '".self::REQUEST."'"));

        $this->client->submit($form, ['comment' => 'Fausse demande (fictif).']);
        self::assertStringContainsString('crédits des artisans sont restitués', $this->client->followRedirect()->filter('.tm-toasts')->text());
        self::assertSame(['cancelled', 'deleted_fraud'], array_values((array) $this->connection()->fetchAssociative('SELECT status, status_reason FROM request.quote_request WHERE id = :id', ['id' => self::REQUEST])));

        $this->close('deleted_fraud');
        $this->close('deleted_fraud');
        self::assertSame('request_cancelled', $this->dossierValue("SELECT status FROM quote.quote WHERE id = '".self::QUOTE."'"));
        self::assertSame('1', $this->dossierValue("SELECT count(*)::text FROM billing.credit_ledger_entry WHERE type = 'refund'"), 'restitution unique');
        self::assertSame(1, DbalValue::int($this->connection()->fetchOne('SELECT free FROM billing.credit_balance b JOIN billing.credit_account a ON a.artisan_id = b.artisan_id WHERE a.user_id = :u', ['u' => self::DOSSIER_USER])));
        self::assertSame('1', $this->dossierValue("SELECT count(*)::text FROM admin.audit_log WHERE action = 'credit.refunded_fraud'"));
    }

    public function testPlainClosureCancelsQuotesWithoutRefund(): void
    {
        $this->close('client_postponed');
        self::assertSame('request_cancelled', $this->dossierValue("SELECT status FROM quote.quote WHERE id = '".self::QUOTE."'"));
        self::assertSame('0', $this->dossierValue("SELECT count(*)::text FROM billing.credit_ledger_entry WHERE type = 'refund'"));
    }

    private function close(string $reason): void
    {
        $handler = self::getContainer()->get(CloseQuotesWithRequest::class);
        \assert($handler instanceof CloseQuotesWithRequest);
        $handler(new QuoteRequestStatusChanged(self::REQUEST, self::CLIENT, 'open', 'cancelled', $reason, new \DateTimeImmutable()));
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);

        return $connection;
    }
}
