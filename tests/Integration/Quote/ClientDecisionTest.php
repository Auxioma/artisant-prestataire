<?php

declare(strict_types=1);

namespace App\Tests\Integration\Quote;

use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\Billing\Application\Credit\CreditLedger;
use App\Identity\Application\Command\RegisterUser;
use App\Quote\Application\Client\AcceptQuote;
use App\Quote\Application\Client\ClientQuotePdf;
use App\Quote\Application\Client\ClientQuotes;
use App\Quote\Application\Client\ClientRequestQuotes;
use App\Quote\Application\Client\MarkQuoteViewed;
use App\Quote\Application\Client\RefuseQuote;
use App\Quote\Application\Editor\SaveQuoteDraft;
use App\Quote\Application\EventHandler\NotifyQuoteDecision;
use App\Quote\Application\Sending\SendQuote;
use App\Quote\Domain\Quote\Event\QuoteStatusChanged;
use App\Quote\Domain\Quote\QuoteRuleViolation;
use App\QuoteRequest\Application\Dispatch\DispatchQuoteRequest;
use App\QuoteRequest\Application\Submission\SubmitQuoteRequest;
use App\Shared\Application\Bus\QueryBus;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * C03 — devis reçus vus par le client (jamais ceux d'un autre), consultation (E-35b), acceptation RG-071/072 (demande
 * attribuée, E-34 avec les coordonnées et l'adresse complète du client RG-022bis, E-13), refus motivé (E-35).
 */
final class ClientDecisionTest extends KernelTestCase
{
    use ArtisanDossier;

    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000f7001';
    private const string OTHER = '0190f5e4-0000-7000-8000-0000000f7002';
    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a7001';
    private const string REQUEST = '0190f5e4-0000-7000-8000-0000000f7101';
    private const string QUOTE = '0190f5e4-0000-7000-8000-0000000f7201';

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
        $this->dossierDispatch(new RegisterUser(self::CLIENT, 'camille.choix@exemple.test', 'cheval agrafe batterie', 'client', 'Camille', 'Choix'));
        $this->dossierDispatch(new RegisterUser(self::OTHER, 'autre.choix@exemple.test', 'cheval agrafe batterie', 'client', 'Alex', 'Autre'));
        $job = $this->dossierValue("SELECT id FROM catalog.job WHERE slug = 'plombier'");
        $this->dossierDispatch(new SubmitQuoteRequest(self::REQUEST, self::CLIENT, $job, '76351', '76600', '12 rue Fictive', 'repair', 'Fuite sous l’évier de la cuisine depuis ce matin (fictif).', [], null, 'week', null, title: 'Fuite sous l’évier'));
        $this->dossierDispatch(new DispatchQuoteRequest(self::REQUEST));
        $this->dossierDispatch(new SaveQuoteDraft(self::DOSSIER_USER, self::REQUEST, self::QUOTE, ['subject' => 'Réparation de la fuite', 'execution_delay' => '2 semaines', 'guarantees' => 'Pièces 2 ans', 'lines' => [
            ['type' => 'labour', 'label' => 'Recherche de fuite', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '90', 'vat' => '1000'],
            ['type' => 'travel', 'label' => 'Déplacement', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '0', 'vat' => '2000'],
        ]]));
        $ledger = self::getContainer()->get(CreditLedger::class);
        \assert($ledger instanceof CreditLedger);
        $ledger->grantFree('0190f5e4-0000-7000-8000-0000000f7301', $this->artisan, self::DOSSIER_USER, 1, null, new \DateTimeImmutable());
        $this->dossierDispatch(new SendQuote(self::DOSSIER_USER, self::REQUEST));
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    public function testClientSeesOnlyHisQuotesAndTheirPdf(): void
    {
        $queries = self::getContainer()->get(QueryBus::class);
        \assert($queries instanceof QueryBus);
        $view = $queries->ask(new ClientRequestQuotes(self::CLIENT, self::REQUEST));
        self::assertInstanceOf(ClientQuotes::class, $view);
        self::assertCount(1, $view->cards);
        $card = $view->cards[0];
        self::assertSame(['Atelier Fictif', 9900, 9000, [1000], '2 semaines', 'Pièces 2 ans', ['Recherche de fuite', 'Déplacement'], true, true], [$card->artisanName, $card->ttcCents, $card->htCents, $card->vatRatesBp, $card->executionDelay, $card->guarantees, $card->mainLines, $card->decidable, $card->isNew()]);
        self::assertNull($queries->ask(new ClientRequestQuotes(self::OTHER, self::REQUEST)), 'demande d’un autre client');
        $pdf = $queries->ask(new ClientQuotePdf(self::CLIENT, self::QUOTE));
        self::assertIsString($pdf);
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertNull($queries->ask(new ClientQuotePdf(self::OTHER, self::QUOTE)));

        $this->dossierDispatch(new MarkQuoteViewed(self::CLIENT, self::QUOTE));
        self::assertSame('viewed', $this->dossierValue("SELECT status FROM quote.quote WHERE id = '".self::QUOTE."'"));
        $this->notify('sent', 'viewed');
        self::assertStringStartsWith('Le client a consulté votre devis DEV-', $this->dossierValue("SELECT body FROM notification.in_app WHERE code = 'E-35b'"));
    }

    public function testAcceptanceAwardsTheRequestAndSharesContacts(): void
    {
        try {
            $this->dossierDispatch(new AcceptQuote(self::OTHER, self::QUOTE));
            self::fail('devis d’une autre demande');
        } catch (QuoteRuleViolation $e) {
            self::assertSame('Devis introuvable.', $e->getMessage());
        }
        $this->dossierDispatch(new AcceptQuote(self::CLIENT, self::QUOTE));
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        self::assertSame('accepted', $this->dossierValue("SELECT status FROM quote.quote WHERE id = '".self::QUOTE."'"));
        self::assertSame('awarded', $this->dossierValue("SELECT status FROM request.quote_request WHERE id = '".self::REQUEST."'"));
        self::assertSame('1', $this->dossierValue("SELECT count(*)::text FROM admin.audit_log WHERE action = 'quote.accepted'"));

        $this->notify('sent', 'accepted');
        self::assertStringContainsString('accepté par Camille Choix', $this->dossierValue("SELECT body FROM notification.in_app WHERE code = 'E-34'"));
        self::assertSame('1', $this->dossierValue("SELECT count(*)::text FROM notification.delivery WHERE code = 'E-34' AND channel = 'email'"));
        self::assertSame('1', $this->dossierValue("SELECT count(*)::text FROM notification.delivery WHERE code = 'E-13' AND channel = 'email'"));

        try {
            $this->dossierDispatch(new RefuseQuote(self::CLIENT, self::QUOTE, 'price', null));
            self::fail('décision définitive');
        } catch (QuoteRuleViolation $e) {
            self::assertSame('Ce devis ne peut plus être refusé.', $e->getMessage());
        }
    }

    public function testRefusalNeedsAReasonAndTellsTheArtisan(): void
    {
        try {
            $this->dossierDispatch(new RefuseQuote(self::CLIENT, self::QUOTE, '', null));
            self::fail('motif obligatoire');
        } catch (QuoteRuleViolation $e) {
            self::assertSame('Choisissez le motif du refus.', $e->getMessage());
        }
        $this->dossierDispatch(new RefuseQuote(self::CLIENT, self::QUOTE, 'price', 'Budget dépassé (fictif)'));
        self::assertSame(['refused', 'Prix — Budget dépassé (fictif)'], array_values((array) $this->connection->fetchAssociative('SELECT status, decision_reason FROM quote.quote WHERE id = :q', ['q' => self::QUOTE])));
        $this->notify('sent', 'refused');
        self::assertStringEndsWith('refusé par le client : Prix — Budget dépassé (fictif).', $this->dossierValue("SELECT body FROM notification.in_app WHERE code = 'E-35'"));
    }

    private function notify(string $from, string $to): void
    {
        $handler = self::getContainer()->get(NotifyQuoteDecision::class);
        \assert($handler instanceof NotifyQuoteDecision);
        $handler(new QuoteStatusChanged(self::QUOTE, self::REQUEST, $this->artisan, $from, $to, null, new \DateTimeImmutable()));
    }
}
