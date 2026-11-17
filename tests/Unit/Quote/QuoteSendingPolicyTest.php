<?php

declare(strict_types=1);

namespace App\Tests\Unit\Quote;

use App\Quote\Domain\Mention\MandatoryMention;
use App\Quote\Domain\Mention\MentionCheck;
use App\Quote\Domain\Quote\QuoteStatus;
use App\Quote\Domain\Sending\QuoteSendingPolicy;
use App\Quote\Domain\Sending\QuoteSendingRefused;
use App\Quote\Domain\Sending\RefusalReason;
use App\Quote\Domain\Sending\SendingFacts;
use PHPUnit\Framework\TestCase;

/** RG-101 — contrôles (a) à (f), dans l'ordre, avec leurs messages. */
final class QuoteSendingPolicyTest extends TestCase
{
    public function testEachCheckRefusesWithItsReason(): void
    {
        $ok = new MentionCheck(MandatoryMention::FreeQuote, true, 'ok');
        $missing = new MentionCheck(MandatoryMention::TravelCosts, false, 'Frais', 'frais de déplacement');
        $cases = [
            [new SendingFacts(false, 1, 3, true, QuoteStatus::Draft, [$ok], 5), RefusalReason::RequestClosed, 'n’accepte plus'],
            [new SendingFacts(false, 3, 3, true, QuoteStatus::Draft, [$ok], 5), RefusalReason::RequestFull, 'vient d’atteindre 3 devis'],
            [new SendingFacts(true, 3, 3, true, QuoteStatus::Draft, [$ok], 5), RefusalReason::RequestFull, 'vient d’atteindre 3 devis'],
            [new SendingFacts(true, 1, 3, false, QuoteStatus::Draft, [$ok], 5), RefusalReason::NotVerified, 'vérifié'],
            [new SendingFacts(true, 1, 3, true, QuoteStatus::Sent, [$ok], 5), RefusalReason::AlreadySent, 'déjà envoyé'],
            [new SendingFacts(true, 1, 3, true, QuoteStatus::Draft, [$ok, $missing], 5), RefusalReason::MissingMentions, 'frais de déplacement'],
            [new SendingFacts(true, 1, 3, true, QuoteStatus::Draft, [$ok], 0), RefusalReason::NoCredit, 'plus de crédit'],
            [new SendingFacts(true, 1, 3, true, QuoteStatus::Draft, [$ok], 5, subscriptionSuspended: true), RefusalReason::SubscriptionSuspended, 'suspendu pour impayé'],
        ];
        $policy = new QuoteSendingPolicy();
        foreach ($cases as [$facts, $reason, $message]) {
            try {
                $policy->assertCanSend($facts);
                self::fail('refus attendu : '.$reason->value);
            } catch (QuoteSendingRefused $e) {
                self::assertSame($reason, $e->reason);
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
        $refused = null;
        try {
            $policy->assertCanSend(new SendingFacts(true, 2, 3, true, QuoteStatus::Draft, [$ok], 1));
        } catch (QuoteSendingRefused $e) {
            $refused = $e;
        }
        self::assertNull($refused, 'dernière place, dernier crédit : envoi accepté');
    }
}
