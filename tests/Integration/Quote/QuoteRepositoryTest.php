<?php

declare(strict_types=1);

namespace App\Tests\Integration\Quote;

use App\Identity\Application\Command\RegisterUser;
use App\Quote\Domain\Quote\Deposit;
use App\Quote\Domain\Quote\LineType;
use App\Quote\Domain\Quote\Quote;
use App\Quote\Domain\Quote\QuoteConditions;
use App\Quote\Domain\Quote\QuoteContent;
use App\Quote\Domain\Quote\QuoteId;
use App\Quote\Domain\Quote\QuoteLine;
use App\Quote\Domain\Quote\QuoteRepository;
use App\Quote\Domain\Quote\QuoteStatus;
use App\Quote\Domain\Quote\VatRate;
use App\QuoteRequest\Application\Submission\SubmitQuoteRequest;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\ArtisanDossier;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** T-501 — persistance du devis : aller-retour complet, versions figées une fois envoyées, unicité demande × artisan. */
final class QuoteRepositoryTest extends KernelTestCase
{
    use ArtisanDossier;

    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000f0001';
    private const string REQUEST = '0190f5e4-0000-7000-8000-0000000f0101';
    private const string QUOTE = '0190f5e4-0000-7000-8000-0000000f0201';

    private Connection $connection;
    private QuoteRepository $quotes;
    private string $artisan;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $quotes = self::getContainer()->get(QuoteRepository::class);
        \assert($connection instanceof Connection && $quotes instanceof QuoteRepository);
        $this->connection = $connection;
        $this->quotes = $quotes;
        $this->prepareReferentials();
        $this->artisan = $this->submittedDossier(200);
        $this->dossierDispatch(new RegisterUser(self::CLIENT, 'camille.devis@exemple.test', 'cheval agrafe batterie', 'client', 'Camille', 'Devis'));
        $job = $this->dossierValue("SELECT id FROM catalog.job WHERE slug = 'plombier'");
        $this->dossierDispatch(new SubmitQuoteRequest(self::REQUEST, self::CLIENT, $job, '76351', '76600', '1 rue Fictive', 'repair', 'Fuite sous l’évier de la cuisine depuis ce matin (fictif).', [], null, 'week', null));
    }

    public function testRoundTripFreezesSentVersionsAndKeepsRevisions(): void
    {
        $now = new \DateTimeImmutable('2026-11-20 10:00:00.123456');
        $quote = Quote::draft(new QuoteId(self::QUOTE), self::REQUEST, $this->artisan, $this->content(10000, 'Brouillon'), $now);
        $this->quotes->save($quote);
        $quote->updateDraft($this->content(12000, 'Remplacement du siphon'), $now);
        $this->quotes->save($quote);
        self::assertSame(1, DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM quote.quote_version')), 'brouillon remplacé, pas empilé');

        $quote->send('DEV-2026-0001', $now);
        $this->quotes->save($quote);
        $quote->revise($this->content(11000, 'Remplacement du siphon (révisé)'), $now->modify('+1 day'));
        $this->quotes->save($quote);

        $loaded = $this->quotes->findByRequestAndArtisan(self::REQUEST, $this->artisan);
        self::assertNotNull($loaded);
        self::assertSame([QuoteStatus::Sent, 'DEV-2026-0001', 2], [$loaded->status(), $loaded->number(), $loaded->current()->number]);
        self::assertSame('Remplacement du siphon', $loaded->versions()[0]->content->subject);
        $v2 = $loaded->current()->content;
        self::assertSame([11000, 1100, 12100, 3630], [$v2->totals->ht->amount, $v2->totals->vat->amount, $v2->totals->ttc->amount, $v2->totals->deposit?->amount]);
        self::assertSame(['Intervention', 'Dépose', 'Siphon PVC'], array_map(static fn (QuoteLine $l): string => $l->label, $v2->lines));
        self::assertSame(2500, $v2->lines[2]->quantityMilli);
        self::assertSame($now->format('Y-m-d H:i:s.u'), $loaded->sentAt()?->format('Y-m-d H:i:s.u'), 'microsecondes conservées');

        try {
            $this->connection->executeStatement("UPDATE quote.quote_version SET subject = 'falsifié' WHERE version = 1");
            self::fail('version envoyée modifiable');
        } catch (DriverException $e) {
            self::assertStringContainsString('modification interdite', $e->getMessage());
        }
    }

    public function testOneQuotePerArtisanAndRequest(): void
    {
        $now = new \DateTimeImmutable();
        $this->quotes->save(Quote::draft(new QuoteId(self::QUOTE), self::REQUEST, $this->artisan, $this->content(10000, 'Premier'), $now));
        $this->expectException(UniqueConstraintViolationException::class);
        $this->quotes->save(Quote::draft(new QuoteId('0190f5e4-0000-7000-8000-0000000f0202'), self::REQUEST, $this->artisan, $this->content(10000, 'Second'), $now));
    }

    private function content(int $labourCents, string $subject): QuoteContent
    {
        return new QuoteContent($subject, [
            new QuoteLine(LineType::Section, 'Intervention'),
            new QuoteLine(LineType::Labour, 'Dépose', 'Dépose de l’ancien siphon', 1000, 'forfait', $labourCents - 1000, 0, VatRate::Intermediate),
            new QuoteLine(LineType::Supply, 'Siphon PVC', null, 2500, 'u', 400, 0, VatRate::Intermediate),
        ], new QuoteConditions(30, 'Sous 8 jours', null, 'Paiement à réception', ['virement', 'chèque']), 0, Deposit::percent(3000));
    }
}
