<?php

declare(strict_types=1);

namespace App\Tests\Integration\Quote;

use App\ArtisanProfile\Application\Quote\QuoteIssuer;
use App\ArtisanProfile\Application\Quote\QuoteIssuerView;
use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\Identity\Application\Command\RegisterUser;
use App\Quote\Application\Editor\SaveQuoteDraft;
use App\Quote\Application\Pdf\QuotePdfService;
use App\Quote\Application\Pdf\QuotePreviewPdf;
use App\Quote\Application\Pdf\QuoteVersionPdf;
use App\Quote\Application\Port\QuotePdfStore;
use App\Quote\Domain\Quote\QuoteId;
use App\Quote\Domain\Quote\QuoteRepository;
use App\Quote\Domain\Quote\QuoteRuleViolation;
use App\QuoteRequest\Application\Dispatch\DispatchQuoteRequest;
use App\QuoteRequest\Application\Pro\ProRequestDetail;
use App\QuoteRequest\Application\Pro\ProRequestView;
use App\QuoteRequest\Application\Submission\SubmitQuoteRequest;
use App\Shared\Application\Bus\QueryBus;
use App\Tests\Support\ArtisanDossier;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** T-503 — PDF du devis : aperçu du brouillon, PDF figé à l'envoi (empreinte SHA-256), relecture contrôlée. */
final class QuotePdfTest extends KernelTestCase
{
    use ArtisanDossier;

    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000f3001';
    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a3001';
    private const string REQUEST = '0190f5e4-0000-7000-8000-0000000f3101';
    private const string QUOTE = '0190f5e4-0000-7000-8000-0000000f3201';

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $this->prepareReferentials();
        $artisan = $this->submittedDossier(200);
        $this->uploadPiece('kbis');
        $this->uploadPiece('insurance_rc');
        $this->dossierDispatch(new ApproveArtisan($artisan, self::ADMIN));
        $connection->executeStatement("UPDATE identity.user_account SET status = 'active', email_verified_at = date_trunc('second', now()) WHERE id = :id", ['id' => self::DOSSIER_USER]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->dossierDispatch(new RegisterUser(self::CLIENT, 'camille.pdf@exemple.test', 'cheval agrafe batterie', 'client', 'Camille', 'Devis'));
        $job = $this->dossierValue("SELECT id FROM catalog.job WHERE slug = 'plombier'");
        $this->dossierDispatch(new SubmitQuoteRequest(self::REQUEST, self::CLIENT, $job, '76351', '76600', '1 rue Fictive', 'repair', 'Fuite sous l’évier de la cuisine depuis ce matin (fictif).', [], null, 'week', null, title: 'Fuite sous l’évier'));
        $this->dossierDispatch(new DispatchQuoteRequest(self::REQUEST));
        $this->dossierDispatch(new SaveQuoteDraft(self::DOSSIER_USER, self::REQUEST, self::QUOTE, [
            'subject' => 'Réparation de la fuite',
            'lines' => [
                ['type' => 'section', 'label' => 'Intervention'],
                ['type' => 'labour', 'label' => 'Recherche de fuite', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '90', 'vat' => '1000'],
                ['type' => 'travel', 'label' => 'Déplacement', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '0', 'vat' => '2000'],
            ],
        ]));
    }

    public function testPreviewThenFrozenPdfIsVerifiedOnRead(): void
    {
        $preview = $this->queries()->ask(new QuotePreviewPdf(self::DOSSIER_USER, self::REQUEST));
        self::assertIsString($preview);
        self::assertStringStartsWith('%PDF-', $preview);

        $quotes = self::getContainer()->get(QuoteRepository::class);
        $pdf = self::getContainer()->get(QuotePdfService::class);
        $store = self::getContainer()->get(QuotePdfStore::class);
        \assert($quotes instanceof QuoteRepository && $pdf instanceof QuotePdfService && $store instanceof QuotePdfStore);
        $quote = $quotes->find(new QuoteId(self::QUOTE));
        $issuer = $this->queries()->ask(new QuoteIssuer(self::DOSSIER_USER));
        $request = $this->queries()->ask(new ProRequestDetail(self::DOSSIER_USER, self::REQUEST));
        self::assertNotNull($quote);
        self::assertInstanceOf(QuoteIssuerView::class, $issuer);
        self::assertInstanceOf(ProRequestView::class, $request);

        $now = new \DateTimeImmutable();
        $frozen = $pdf->freeze($pdf->document($issuer, $request, $quote->current()->content, 'DEV-2026-0001', 1, $now, false));
        $quote->send('DEV-2026-0001', $now, $frozen);
        $quotes->save($quote);
        self::assertSame($frozen->sha256, $this->dossierValue("SELECT pdf_sha256 FROM quote.quote_version WHERE quote_id = '".self::QUOTE."'"));

        $bytes = $this->queries()->ask(new QuoteVersionPdf(self::DOSSIER_USER, self::QUOTE, 1));
        self::assertIsString($bytes);
        self::assertSame($frozen->sha256, hash('sha256', $bytes));
        self::assertNull($this->queries()->ask(new QuoteVersionPdf(self::CLIENT, self::QUOTE, 1)), 'réservé à l’artisan auteur');

        $store->put($frozen->key, $bytes.'altéré');
        $this->expectException(QuoteRuleViolation::class);
        $this->queries()->ask(new QuoteVersionPdf(self::DOSSIER_USER, self::QUOTE, 1));
    }

    private function queries(): QueryBus
    {
        $bus = self::getContainer()->get(QueryBus::class);
        \assert($bus instanceof QueryBus);

        return $bus;
    }
}
