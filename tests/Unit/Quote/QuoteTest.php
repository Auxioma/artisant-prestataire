<?php

declare(strict_types=1);

namespace App\Tests\Unit\Quote;

use App\Quote\Domain\Quote\Event\QuoteRevised;
use App\Quote\Domain\Quote\Event\QuoteStatusChanged;
use App\Quote\Domain\Quote\LineType;
use App\Quote\Domain\Quote\Quote;
use App\Quote\Domain\Quote\QuoteConditions;
use App\Quote\Domain\Quote\QuoteContent;
use App\Quote\Domain\Quote\QuoteId;
use App\Quote\Domain\Quote\QuoteLine;
use App\Quote\Domain\Quote\QuoteRuleViolation;
use App\Quote\Domain\Quote\QuoteStatus;
use PHPUnit\Framework\TestCase;

/** RG-121 / RG-102 / RG-103 — machine à états du devis. */
final class QuoteTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-20 10:00:00');
    }

    public function testDraftIsEditableThenSentWithNumberAndValidity(): void
    {
        $quote = $this->draft();
        $quote->updateDraft($this->content(20000), $this->now);
        self::assertSame(QuoteStatus::Draft, $quote->status());
        self::assertSame([], $quote->releaseEvents(), 'un brouillon ne publie rien');

        $quote->send('DEV-2026-0001', $this->now);
        self::assertSame(['sent', 'DEV-2026-0001', '2026-12-20'], [$quote->status()->value, $quote->number(), $quote->validUntil()?->format('Y-m-d')]);
        self::assertNotNull($quote->current()->sentAt);
        $events = $quote->releaseEvents();
        self::assertInstanceOf(QuoteStatusChanged::class, $events[0]);

        $this->expectException(QuoteRuleViolation::class);
        $quote->updateDraft($this->content(1), $this->now);
    }

    public function testRevisionAddsAVersionUntilDecision(): void
    {
        $quote = $this->sent();
        $quote->markViewed($this->now);
        $quote->revise($this->content(15000), $this->now->modify('+1 day'));
        self::assertSame(2, $quote->current()->number);
        self::assertSame(QuoteStatus::Viewed, $quote->status());
        self::assertSame('2026-12-21', $quote->validUntil()?->format('Y-m-d'), 'la validité repart');
        self::assertInstanceOf(QuoteRevised::class, array_values(array_filter($quote->releaseEvents(), static fn ($e): bool => $e instanceof QuoteRevised))[0] ?? null);

        $quote->accept($this->now);
        $this->expectExceptionMessage('ne peut plus être révisé');
        $quote->revise($this->content(1), $this->now);
    }

    public function testClientRemindedOnceAfterTheDelayUntilViewed(): void
    {
        $quote = $this->sent();
        self::assertEquals($this->now->modify('+3 days'), $quote->reminderAvailableAt(3, 1));
        try {
            $quote->remind($this->now->modify('+2 days'), 3, 1);
            self::fail('trop tôt');
        } catch (QuoteRuleViolation $e) {
            self::assertStringContainsString('à partir du 23/11/2026', $e->getMessage());
        }
        $quote->remind($this->now->modify('+3 days'), 3, 1);
        self::assertSame(1, $quote->reminderCount());
        self::assertCount(1, $quote->releaseEvents());
        self::assertNull($quote->reminderAvailableAt(3, 1), 'relance unique');

        $viewed = $this->sent();
        $viewed->markViewed($this->now);
        self::assertNull($viewed->reminderAvailableAt(3, 1), 'consulté : plus de relance');
        $this->expectExceptionMessage('déjà consulté');
        $viewed->remind($this->now->modify('+5 days'), 3, 1);
    }

    public function testWithdrawOnlyBeforeTheClientViewsIt(): void
    {
        $quote = $this->sent();
        $quote->withdraw($this->now);
        self::assertSame(QuoteStatus::Withdrawn, $quote->status());

        $viewed = $this->sent();
        $viewed->markViewed($this->now);
        $this->expectExceptionMessage('ne peut plus être retiré');
        $viewed->withdraw($this->now);
    }

    public function testDecisionsAreFinal(): void
    {
        $quote = $this->sent();
        try {
            $quote->refuse(' ', $this->now);
            self::fail('motif obligatoire');
        } catch (QuoteRuleViolation) {
        }
        $quote->refuse('Trop cher', $this->now);
        self::assertSame(['refused', 'Trop cher'], [$quote->status()->value, $quote->decisionReason()]);
        $quote->cancelWithRequest($this->now);
        self::assertSame(QuoteStatus::Refused, $quote->status(), 'décision finale conservée');

        $expiring = $this->sent();
        try {
            $expiring->expire($this->now);
            self::fail('encore valable');
        } catch (QuoteRuleViolation) {
        }
        $expiring->expire($this->now->modify('+31 days'));
        self::assertSame(QuoteStatus::Expired, $expiring->status());

        $this->expectException(QuoteRuleViolation::class);
        $expiring->accept($this->now);
    }

    private function draft(): Quote
    {
        return Quote::draft(new QuoteId('0190f5e4-0000-7000-8000-00000000f001'), '0190f5e4-0000-7000-8000-00000000e001', '0190f5e4-0000-7000-8000-00000000a001', $this->content(10000), $this->now);
    }

    private function sent(): Quote
    {
        $quote = $this->draft();
        $quote->send('DEV-2026-0001', $this->now);
        $quote->releaseEvents();

        return $quote;
    }

    private function content(int $priceCents): QuoteContent
    {
        return new QuoteContent('Remplacement du chauffe-eau', [new QuoteLine(LineType::Labour, 'Pose', null, 1000, 'forfait', $priceCents)], new QuoteConditions(30));
    }
}
