<?php

declare(strict_types=1);

namespace App\Tests\Unit\Quote;

use App\Quote\Domain\Quote\Deposit;
use App\Quote\Domain\Quote\LineType;
use App\Quote\Domain\Quote\QuoteConditions;
use App\Quote\Domain\Quote\QuoteContent;
use App\Quote\Domain\Quote\QuoteLine;
use App\Quote\Domain\Quote\QuoteRuleViolation;
use App\Quote\Domain\Quote\VatRate;
use PHPUnit\Framework\TestCase;

/** PR04.2 — arrondi à la ligne, TVA par taux, remise globale, franchise en base, acompte, validations. */
final class QuoteTotalsTest extends TestCase
{
    public function testLineIsRoundedToTheCentAfterQuantityAndDiscount(): void
    {
        $line = new QuoteLine(LineType::Supply, 'Carrelage', null, 2500, 'm²', 1234, 1000);
        self::assertSame(2776, $line->totalHt()->amount, '2,5 × 12,34 = 30,85 ; remise 10 % = 3,085 → 3,09');
        self::assertSame(0, new QuoteLine(LineType::Section, 'Salle de bain')->totalHt()->amount);
    }

    public function testVatIsComputedPerRateAndRoundedPerRate(): void
    {
        $totals = $this->content([
            new QuoteLine(LineType::Labour, 'Pose', null, 1000, 'forfait', 10000, 0, VatRate::Standard),
            new QuoteLine(LineType::Supply, 'Mitigeur', null, 1000, 'u', 5000, 0, VatRate::Intermediate),
            new QuoteLine(LineType::Supply, 'Isolant', null, 1000, 'u', 1999, 0, VatRate::Reduced),
            new QuoteLine(LineType::Comment, 'Fourniture comprise'),
        ])->totals;
        self::assertSame(16999, $totals->ht->amount);
        self::assertSame(2000 + 500 + 110, $totals->vat->amount, '19,99 × 5,5 % = 1,099 → 1,10');
        self::assertSame(19609, $totals->ttc->amount);
        self::assertSame([2000, 1000, 550], array_keys($totals->byRate));
    }

    public function testGlobalDiscountIsSpreadOverEachRate(): void
    {
        $totals = $this->content([
            new QuoteLine(LineType::Labour, 'Pose', null, 1000, null, 10000, 0, VatRate::Standard),
            new QuoteLine(LineType::Supply, 'Mitigeur', null, 1000, null, 5000, 0, VatRate::Intermediate),
        ], 1000)->totals;
        self::assertSame(15000, $totals->grossHt->amount);
        self::assertSame(1500, $totals->discount->amount);
        self::assertSame(13500, $totals->ht->amount);
        self::assertSame(1800 + 450, $totals->vat->amount);
        self::assertSame(15750, $totals->ttc->amount);
    }

    public function testVatExemptArtisanChargesNoVat(): void
    {
        $content = $this->content([new QuoteLine(LineType::Labour, 'Pose', null, 1000, null, 10000, 0, VatRate::Intermediate)], 0, null, true);
        self::assertSame(0, $content->totals->vat->amount);
        self::assertSame(10000, $content->totals->ttc->amount);
        self::assertSame([0], array_keys($content->totals->byRate));
        self::assertFalse($content->needsClientCertificate(), 'aucune attestation de taux réduit en franchise');
    }

    public function testDepositAndReducedRateReminder(): void
    {
        $content = $this->content([new QuoteLine(LineType::Labour, 'Pose', null, 1000, null, 10000, 0, VatRate::Intermediate)], 0, Deposit::percent(3000));
        self::assertSame(3300, $content->totals->deposit?->amount, '30 % de 110,00 €');
        self::assertTrue($content->needsClientCertificate());

        $this->expectException(QuoteRuleViolation::class);
        $this->content([new QuoteLine(LineType::Labour, 'Pose', null, 1000, null, 1000)], 0, Deposit::amount(5000));
    }

    public function testInvalidLinesAndNullTotalAreRefused(): void
    {
        foreach ([static fn () => new QuoteLine(LineType::Labour, '  '), static fn () => new QuoteLine(LineType::Labour, 'Pose', null, 0), static fn () => new QuoteLine(LineType::Labour, 'Pose', null, 1000, null, -1), static fn () => new QuoteConditions(181)] as $i => $build) {
            try {
                $build();
                self::fail('cas '.$i.' accepté');
            } catch (QuoteRuleViolation) {
            }
        }
        $this->expectExceptionMessage('Le montant du devis ne peut pas être nul.');
        $this->content([new QuoteLine(LineType::Section, 'Préparation')])->assertSendable();
    }

    /** @param list<QuoteLine> $lines */
    private function content(array $lines, int $discountBp = 0, ?Deposit $deposit = null, bool $vatExempt = false): QuoteContent
    {
        return new QuoteContent('Rénovation de la salle de bain', $lines, new QuoteConditions(30), $discountBp, $deposit, $vatExempt);
    }
}
