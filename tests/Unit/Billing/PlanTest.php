<?php

declare(strict_types=1);

namespace App\Tests\Unit\Billing;

use App\Billing\Domain\Plan\BillingInterval;
use App\Billing\Domain\Plan\Plan;
use App\Billing\Domain\Plan\PlanId;
use App\Billing\Domain\Plan\PlanRuleViolation;
use App\Billing\Domain\Plan\PlanStatus;
use App\Billing\Domain\Plan\PlanTerms;
use App\Billing\Domain\Plan\PlanVisibility;
use PHPUnit\Framework\TestCase;

/** BO08.1 — publication (prix mensuel + volume ou illimité plafonné), prix versionnés, archivage, suppression, privé. */
final class PlanTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-20 10:00:00');
    }

    public function testPublishNeedsAMonthlyPriceAndAVolume(): void
    {
        $plan = Plan::draft(new PlanId('0190f5e4-0000-7000-8000-00000000c001'), 'essentiel', $this->terms(30), 1, $this->now);
        try {
            $plan->publish($this->now);
            self::fail('prix mensuel manquant');
        } catch (PlanRuleViolation $e) {
            self::assertStringContainsString('prix HT mensuel', $e->getMessage());
        }
        $plan->changePrice(BillingInterval::Month, 2900, '0190f5e4-0000-7000-8000-00000000c101', $this->now);
        $plan->publish($this->now);
        self::assertSame(PlanStatus::Published, $plan->status());

        $unlimited = Plan::draft(new PlanId('0190f5e4-0000-7000-8000-00000000c002'), 'premium', $this->terms(null, true), 3, $this->now);
        $unlimited->changePrice(BillingInterval::Month, 9900, '0190f5e4-0000-7000-8000-00000000c102', $this->now);
        $this->expectExceptionMessage('plafond d’usage raisonnable');
        $unlimited->publish($this->now);
    }

    public function testNewPriceReplacesTheActiveOneAndPublishedKeepsAMonthlyPrice(): void
    {
        $plan = Plan::draft(new PlanId('0190f5e4-0000-7000-8000-00000000c003'), 'pro', $this->terms(80), 2, $this->now);
        $plan->changePrice(BillingInterval::Month, 5900, '0190f5e4-0000-7000-8000-00000000c103', $this->now);
        $plan->changePrice(BillingInterval::Month, 5900, '0190f5e4-0000-7000-8000-00000000c104', $this->now);
        self::assertSame('0190f5e4-0000-7000-8000-00000000c103', $plan->price(BillingInterval::Month)?->id, 'même montant : prix inchangé');
        $plan->changePrice(BillingInterval::Month, 6400, '0190f5e4-0000-7000-8000-00000000c105', $this->now);
        self::assertSame(6400, $plan->price(BillingInterval::Month)?->amountCents);
        $plan->publish($this->now);

        try {
            $plan->changePrice(BillingInterval::Month, null, '0190f5e4-0000-7000-8000-00000000c106', $this->now);
            self::fail('prix mensuel retiré d’un forfait publié');
        } catch (PlanRuleViolation) {
        }
        try {
            $plan->assertDeletable(0);
            self::fail('suppression d’un forfait publié');
        } catch (PlanRuleViolation $e) {
            self::assertStringContainsString('archivez-le', $e->getMessage());
        }
        $plan->archive($this->now);
        $this->expectExceptionMessage('archivé');
        $plan->revise($this->terms(90), $this->now);
    }

    public function testTermsRules(): void
    {
        foreach ([
            fn () => $this->terms(0),
            static fn () => new PlanTerms('Partenaire', null, 30, false, null, [], null, false, false, null, PlanVisibility::Private, null),
            static fn () => new PlanTerms('Pro', null, 30, false, 300, [], null, false, false, null, PlanVisibility::Public, null),
            static fn () => new PlanTerms(' ', null, 30, false, null, [], null, false, false, null, PlanVisibility::Public, null),
        ] as $i => $build) {
            $refused = false;
            try {
                $build();
            } catch (PlanRuleViolation) {
                $refused = true;
            }
            self::assertTrue($refused, 'règle '.$i);
        }
        self::assertTrue(new PlanTerms('Partenaire', null, 30, false, null, [], null, false, false, null, PlanVisibility::Private, 'CAPEB-76')->isSellable());
    }

    private function terms(?int $quotes, bool $unlimited = false): PlanTerms
    {
        return new PlanTerms('Forfait', null, $quotes, $unlimited, null, ['Avantage fictif'], null, false, false, null, PlanVisibility::Public, null);
    }
}
