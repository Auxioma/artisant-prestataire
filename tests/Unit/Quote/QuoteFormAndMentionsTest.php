<?php

declare(strict_types=1);

namespace App\Tests\Unit\Quote;

use App\Quote\Application\Editor\QuoteForm;
use App\Quote\Domain\Mention\IssuerFacts;
use App\Quote\Domain\Mention\MandatoryMention;
use App\Quote\Domain\Mention\MandatoryMentionsChecker;
use App\Quote\Domain\Quote\LineType;
use App\Quote\Domain\Quote\QuoteRuleViolation;
use App\Quote\Domain\Quote\VatRate;
use PHPUnit\Framework\TestCase;

/** PR04 — saisie au format français ; RG-120 — mentions obligatoires. */
final class QuoteFormAndMentionsTest extends TestCase
{
    public function testFrenchInputIsParsed(): void
    {
        self::assertSame(115050, QuoteForm::decimal('1 150,50', 2, 'x'));
        self::assertSame(2500, QuoteForm::decimal('2,5', 3, 'x'));
        $content = QuoteForm::content([
            'subject' => 'Salle de bain',
            'validity_days' => '45',
            'global_discount' => '5',
            'deposit_type' => 'percent',
            'deposit_value' => '30',
            'lines' => [
                ['type' => 'section', 'label' => 'Dépose'],
                ['type' => 'labour', 'label' => 'Pose', 'quantity' => '8', 'unit' => 'h', 'unit_price' => '55,00', 'vat' => '1000'],
                ['type' => 'supply', 'label' => 'Receveur', 'quantity' => '1', 'unit_price' => '890', 'discount' => '5 %', 'vat' => '1000'],
                ['type' => 'service', 'label' => '', 'quantity' => '', 'unit_price' => ''],
            ],
        ], false, 30);
        self::assertCount(3, $content->lines, 'ligne vide ignorée');
        self::assertSame(LineType::Section, $content->lines[0]->type);
        self::assertSame([8000, 5500, VatRate::Intermediate], [$content->lines[1]->quantityMilli, $content->lines[1]->unitPriceHt, $content->lines[1]->vatRate]);
        self::assertSame(500, $content->lines[2]->discountBp);
        self::assertSame([45, 500], [$content->conditions->validityDays, $content->globalDiscountBp]);
        self::assertNotNull($content->deposit);

        $this->expectExceptionMessage('Prix unitaire invalide.');
        QuoteForm::content(['subject' => 'X', 'lines' => [['type' => 'labour', 'label' => 'Pose', 'unit_price' => '12,345']]], false, 30);
    }

    public function testMentionsAreCheckedAndMissingOnesListed(): void
    {
        $checker = new MandatoryMentionsChecker();
        $content = QuoteForm::content(['subject' => 'Salle de bain', 'lines' => [['type' => 'labour', 'label' => 'Pose', 'quantity' => '1', 'unit_price' => '100']]], false, 30);
        $issuer = new IssuerFacts('Atelier Fictif', '73282932000074', '1 rue Fictive 76600 Le Havre', true, true, true, false);
        $checks = $checker->check($content, $issuer, MandatoryMention::cases());
        $failed = array_values(array_map(static fn ($c): string => $c->mention->value, array_filter($checks, static fn ($c): bool => !$c->ok)));
        self::assertSame(['insurance', 'travel_costs'], $failed);
        try {
            $checker->assertComplete($checks);
            self::fail('envoi bloqué attendu');
        } catch (QuoteRuleViolation $e) {
            self::assertSame('Il manque des informations obligatoires : assurance décennale, frais de déplacement (ligne « Déplacement », même gratuite, ou mention dans les conditions).', $e->getMessage());
        }

        $complete = QuoteForm::content(['subject' => 'Salle de bain', 'special_conditions' => 'Déplacement offert.', 'lines' => [['type' => 'labour', 'label' => 'Pose', 'quantity' => '1', 'unit_price' => '100']]], false, 30);
        $checks = $checker->check($complete, new IssuerFacts('Atelier Fictif', '73282932000074', '1 rue Fictive', true, true, true, true), MandatoryMention::cases());
        $checker->assertComplete($checks);
        self::assertSame([], $checker->check($complete, $issuer, []), 'liste administrable : aucune mention contrôlée');
    }
}
