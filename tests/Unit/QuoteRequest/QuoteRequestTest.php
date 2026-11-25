<?php

declare(strict_types=1);

namespace App\Tests\Unit\QuoteRequest;

use App\QuoteRequest\Domain\Request\DuplicateKey;
use App\QuoteRequest\Domain\Request\Event\QuoteRequestStatusChanged;
use App\QuoteRequest\Domain\Request\Event\QuoteRequestSubmitted;
use App\QuoteRequest\Domain\Request\ProjectType;
use App\QuoteRequest\Domain\Request\QuoteRequest;
use App\QuoteRequest\Domain\Request\QuoteRequestId;
use App\QuoteRequest\Domain\Request\RequestDetails;
use App\QuoteRequest\Domain\Request\RequestRuleViolation;
use App\QuoteRequest\Domain\Request\RequestStatus;
use App\QuoteRequest\Domain\Request\SubmissionTerms;
use App\QuoteRequest\Domain\Request\Timing;
use PHPUnit\Framework\TestCase;

/** RG-066 (machine à états, ADR-0005), RG-064 (échéance, relance unique), RG-070 (complète, réouverture), §4.2 (max figé). */
final class QuoteRequestTest extends TestCase
{
    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000e0001';
    private const string JOB = '0190f5e4-0000-7000-8000-0000000e0002';
    private const string ARTISAN = '0190f5e4-0000-7000-8000-0000000e0003';
    private const int EXPIRY_DAYS = 30;
    private const int THRESHOLD = 70;

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-16 10:00:00');
    }

    public function testTransitionTableMatchesRg066(): void
    {
        self::assertTrue(RequestStatus::Open->canTransitionTo(RequestStatus::Full));
        self::assertTrue(RequestStatus::Full->canTransitionTo(RequestStatus::Open));
        self::assertTrue(RequestStatus::Expired->canTransitionTo(RequestStatus::Open));
        self::assertFalse(RequestStatus::PendingModeration->canTransitionTo(RequestStatus::Full), 'aucun devis avant diffusion');
        self::assertFalse(RequestStatus::Awarded->canTransitionTo(RequestStatus::Cancelled), 'une demande attribuée s’annule par l’annulation de l’acceptation (RG-072)');
        foreach ([RequestStatus::Completed, RequestStatus::Cancelled, RequestStatus::Rejected] as $final) {
            self::assertTrue($final->isFinal());
            foreach (RequestStatus::cases() as $to) {
                self::assertFalse($final->canTransitionTo($to));
            }
        }
    }

    public function testLowRiskRequestIsPublishedWithFrozenTermsAndExpiry(): void
    {
        $request = $this->request(maxQuotes: 3);
        self::assertSame(RequestStatus::Open, $request->status());
        self::assertSame(3, $request->maxQuotes());
        self::assertSame('2026-12-16 10:00', $request->expiresAt()?->format('Y-m-d H:i'), 'RG-064 : 30 jours après la publication');
        $events = $request->releaseEvents();
        self::assertInstanceOf(QuoteRequestSubmitted::class, $events[0]);
        self::assertSame('open', $events[0]->status);
        self::assertTrue($events[0]->broadcastToOthers, 'demande non directe : diffusée');
    }

    public function testRiskyRequestWaitsForModerationBeforeItsExpiryStarts(): void
    {
        $request = $this->request(risk: self::THRESHOLD);
        self::assertSame(RequestStatus::PendingModeration, $request->status());
        self::assertNull($request->expiresAt());
        $this->expectRule(static fn () => $request->recordQuote(new \DateTimeImmutable('2026-11-16 11:00')), 'n’accepte plus de devis');

        $request->approve(self::EXPIRY_DAYS, new \DateTimeImmutable('2026-11-17 09:00'));
        self::assertSame(RequestStatus::Open, $request->status());
        self::assertSame('2026-12-17', $request->expiresAt()?->format('Y-m-d'));

        $rejected = $this->request(risk: 90);
        $rejected->reject('spam', $this->now);
        self::assertSame(RequestStatus::Rejected, $rejected->status());
        self::assertSame('spam', $rejected->statusReason());
    }

    public function testLastQuoteMakesItFullAndWithdrawalFreesTheSlot(): void
    {
        $request = $this->request(maxQuotes: 2);
        $request->recordQuote($this->now);
        self::assertSame(1, $request->remainingSlots());
        $request->recordQuote($this->now);
        self::assertSame(RequestStatus::Full, $request->status(), 'RG-070');
        $this->expectRule(static fn () => $request->recordQuote(new \DateTimeImmutable('2026-11-16 12:00')), 'n’accepte plus de devis');

        $request->releaseQuote($this->now);
        self::assertSame(RequestStatus::Open, $request->status(), 'RG-103 : place libérée');
        self::assertSame(1, $request->activeQuotesCount());
        self::assertSame(2, $request->receivedQuotesCount());
        $changes = array_values(array_filter($request->releaseEvents(), static fn (object $e): bool => $e instanceof QuoteRequestStatusChanged));
        self::assertSame(['open>full', 'full>open'], array_map(static fn (QuoteRequestStatusChanged $e): string => $e->from.'>'.$e->to, $changes));
    }

    public function testFullRequestReopensOnceAfterAllQuotesRefused(): void
    {
        $request = $this->request(maxQuotes: 1);
        $request->recordQuote($this->now);
        $this->expectRule(static fn () => $request->reopen(1, false, new \DateTimeImmutable()), 'Refusez d’abord');
        $request->reopen(1, true, $this->now);
        self::assertSame(RequestStatus::Open, $request->status());
        self::assertSame(1, $request->remainingSlots(), '{max} nouvelles places');
        $request->recordQuote($this->now);
        $this->expectRule(static fn () => $request->reopen(1, true, new \DateTimeImmutable()), 'déjà été rouverte');
        $this->expectRule(fn () => $this->request()->reopen(0, true, new \DateTimeImmutable()), 'Seule une demande complète');
    }

    public function testEditingIsLockedOnceAQuoteWasReceived(): void
    {
        $request = $this->request(maxQuotes: 3);
        $request->edit($this->details('Fuite sous l’évier de la cuisine, joint à remplacer rapidement.'), $this->now);
        self::assertStringStartsWith('Fuite sous', $request->details()->description);
        $request->recordQuote($this->now);
        $request->releaseQuote($this->now);
        $this->expectRule(fn () => $request->edit($this->details(), $this->now), 'ajoutez un complément');
    }

    public function testExpiryRelaunchOnceAndCancellation(): void
    {
        $request = $this->request();
        self::assertFalse($request->isDue(new \DateTimeImmutable('2026-12-16 09:59')));
        $this->expectRule(static fn () => $request->expire(new \DateTimeImmutable('2026-12-16 09:59')), 'pas arrivée à échéance');
        $request->expire(new \DateTimeImmutable('2026-12-16 10:00'));
        self::assertSame(RequestStatus::Expired, $request->status());

        $request->relaunch(self::EXPIRY_DAYS, new \DateTimeImmutable('2026-12-20 08:00'));
        self::assertSame(RequestStatus::Open, $request->status());
        self::assertSame('2027-01-19', $request->expiresAt()?->format('Y-m-d'));
        $request->expire(new \DateTimeImmutable('2027-01-20'));
        $this->expectRule(static fn () => $request->relaunch(self::EXPIRY_DAYS, new \DateTimeImmutable('2027-01-21')), 'déjà été relancée');

        $request->cancel('Projet abandonné', new \DateTimeImmutable('2027-01-21'));
        self::assertSame(RequestStatus::Cancelled, $request->status());
        $this->expectRule(static fn () => $request->cancel('encore', new \DateTimeImmutable()), 'ne peut plus être annulée');
    }

    public function testAwardRevokeAndCompletion(): void
    {
        $request = $this->request(maxQuotes: 1);
        $request->recordQuote($this->now);
        $request->award($this->now);
        self::assertSame(RequestStatus::Awarded, $request->status());
        self::assertFalse($request->isDue(new \DateTimeImmutable('2027-06-01')), 'RG-064 : une demande attribuée n’expire pas');
        $this->expectRule(static fn () => $request->cancel('x', new \DateTimeImmutable()), 'ne peut plus être annulée');
        $request->revokeAward('RDV annulé', $this->now);
        self::assertSame(RequestStatus::Full, $request->status(), 'RG-072 : retour à « complète », la place reste occupée');
        $request->award($this->now);
        $request->complete($this->now);
        self::assertSame(RequestStatus::Completed, $request->status());
    }

    public function testDirectRequestAndValidation(): void
    {
        $direct = QuoteRequest::submit(new QuoteRequestId('0190f5e4-0000-7000-8000-0000000e0010'), 'DM-2026-000002', self::CLIENT, self::JOB, self::ARTISAN, false, '76351', '76600', DuplicateKey::of(self::JOB, '1 rue Fictive'), $this->details(), $this->terms(), $this->now);
        self::assertFalse($direct->broadcastToOthers());
        self::assertSame(self::ARTISAN, $direct->targetArtisanId());

        $this->expectRule(fn () => $this->details('Trop court'), '30 caractères minimum');
        $this->expectRule(fn () => $this->details('Appelez-moi au 06 12 34 56 78 pour la fuite de la cuisine.'), 'n’indiquez pas vos coordonnées');
        $this->expectRule(static fn () => new RequestDetails(ProjectType::Repair, str_repeat('a', 40), [], null, Timing::Date, null), 'date précise');
        $this->expectRule(static fn () => new SubmissionTerms(0, self::EXPIRY_DAYS, 0, [], self::THRESHOLD), 'Conditions de dépôt');
        self::assertSame(DuplicateKey::of(self::JOB, '12, Rue de l’Église')->value, DuplicateKey::of(strtoupper(self::JOB), '12 rue de l eglise ')->value, 'adresse normalisée');
        self::assertNotSame(DuplicateKey::of(self::JOB, '12 rue de l’Église')->value, DuplicateKey::of(self::ARTISAN, '12 rue de l’Église')->value, 'autre métier');
    }

    private function request(int $maxQuotes = 3, int $risk = 0): QuoteRequest
    {
        return QuoteRequest::submit(new QuoteRequestId('0190f5e4-0000-7000-8000-0000000e0011'), 'DM-2026-000001', self::CLIENT, self::JOB, null, false, '76351', '76600', DuplicateKey::of(self::JOB, '1 rue Fictive'), $this->details(), $this->terms($maxQuotes, $risk), $this->now);
    }

    private function terms(int $maxQuotes = 3, int $risk = 0): SubmissionTerms
    {
        return new SubmissionTerms($maxQuotes, self::EXPIRY_DAYS, $risk, [], self::THRESHOLD);
    }

    private function details(string $description = 'Remplacement d’un chauffe-eau électrique de 200 litres (demande fictive).'): RequestDetails
    {
        return new RequestDetails(ProjectType::Installation, $description, ['logement' => 'maison', 'acces' => ['rez-de-chaussée']], '1 000 – 2 000 €', Timing::Month, null);
    }

    private function expectRule(callable $action, string $message): void
    {
        try {
            $action();
            self::fail('RequestRuleViolation attendue : '.$message);
        } catch (RequestRuleViolation $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }
}
