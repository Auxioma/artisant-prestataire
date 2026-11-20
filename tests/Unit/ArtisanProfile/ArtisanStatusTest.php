<?php

declare(strict_types=1);

namespace App\Tests\Unit\ArtisanProfile;

use App\ArtisanProfile\Domain\Profile\ArtisanProfile;
use App\ArtisanProfile\Domain\Profile\ArtisanProfileId;
use App\ArtisanProfile\Domain\Profile\ArtisanRuleViolation;
use App\ArtisanProfile\Domain\Profile\ArtisanStatus;
use App\ArtisanProfile\Domain\Profile\CompanyDetails;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanMoreInfoRequested;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanReinstated;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanRejected;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanSuspended;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanVerified;
use App\ArtisanProfile\Domain\Profile\InsuranceRequirements;
use App\ArtisanProfile\Domain\Profile\ProfileCompletion;
use App\ArtisanProfile\Domain\Profile\RejectionReason;
use App\ArtisanProfile\Domain\Profile\ServiceArea;
use App\ArtisanProfile\Domain\Profile\VatStatus;
use App\Catalog\Domain\Job\JobId;
use App\Shared\Domain\ValueObject\Siret;
use PHPUnit\Framework\TestCase;

/** RG-043 (machine à états, ADR-0005) et PR08.1 (complétude pondérée, RG-011). */
final class ArtisanStatusTest extends TestCase
{
    private const string ADMIN = '0190f5e4-0000-7000-8000-00000000ad01';
    private const array WEIGHTS = ['logo' => 0.15, 'description' => 0.15, 'photos' => 0.20, 'hours' => 0.10, 'zone' => 0.15, 'services' => 0.15, 'labels' => 0.10];
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-13 10:00:00');
    }

    private function profile(bool $submitted = true): ArtisanProfile
    {
        $p = ArtisanProfile::start(new ArtisanProfileId('0190f5e4-0000-7000-8000-000000000c02'), '0190f5e4-0000-7000-8000-000000000c01', $this->now);
        $p->declareCompany(new CompanyDetails(new Siret('90000000100017'), 'Plomberie Fictive', null, null, null, null, VatStatus::Franchise, null, '76600', 'Le Havre', null, null, true), $this->now);
        $p->chooseJobs([new JobId('0190f5e4-0000-7000-8000-000000000c11')], new JobId('0190f5e4-0000-7000-8000-000000000c11'), 5, $this->now);
        $p->defineServiceArea(ServiceArea::radius('76341', 30, 5, 150), $this->now);
        $p->declareInsurances([], [], new InsuranceRequirements(false, false, false), $this->now);
        $p->completeDocumentsStep($this->now);
        if ($submitted) {
            $p->submit('Plombier au Havre.', $this->now);
        }
        $p->releaseEvents();

        return $p;
    }

    public function testApprovalMakesTheProfilePublic(): void
    {
        $p = $this->profile();
        self::assertFalse($p->isPublic());

        $p->approve(self::ADMIN, $this->now);

        self::assertSame(ArtisanStatus::Verified, $p->status());
        self::assertTrue($p->isPublic() && $p->canSendQuotes());
        self::assertEquals($this->now, $p->verifiedAt());
        self::assertInstanceOf(ArtisanVerified::class, $p->releaseEvents()[0]);
    }

    public function testRejectionNeedsANormalizedReasonAndCommentForOther(): void
    {
        $p = $this->profile();
        try {
            $p->reject(self::ADMIN, RejectionReason::Other, '  ', $this->now);
            self::fail('« Autre motif » sans commentaire accepté');
        } catch (ArtisanRuleViolation $e) {
            self::assertSame('Précisez le motif du refus.', $e->getMessage());
        }

        $p->reject(self::ADMIN, RejectionReason::InvalidDocuments, 'Kbis illisible', $this->now);

        self::assertSame([ArtisanStatus::Rejected, 'invalid_documents', 'Kbis illisible'], [$p->status(), $p->statusReason(), $p->statusComment()]);
        self::assertFalse($p->isPublic());
        self::assertInstanceOf(ArtisanRejected::class, $p->releaseEvents()[0]);
        $this->expectExceptionMessage('refusé');
        $p->defineServiceArea(ServiceArea::list(['76'], []), $this->now);
    }

    public function testMoreInfoKeepsTheDossierPending(): void
    {
        $p = $this->profile();
        $p->requestMoreInfo(self::ADMIN, 'Attestation RC Pro à jour', $this->now);

        self::assertSame([ArtisanStatus::PendingVerification, 'more_info'], [$p->status(), $p->statusReason()]);
        self::assertInstanceOf(ArtisanMoreInfoRequested::class, $p->releaseEvents()[0]);
    }

    public function testSuspensionAndReinstatementReturnToThePreviousStatus(): void
    {
        $verified = $this->profile();
        $verified->approve(self::ADMIN, $this->now);
        $verified->suspend(self::ADMIN, 'Signalements répétés', $this->now);
        self::assertSame(ArtisanStatus::Suspended, $verified->status());
        self::assertFalse($verified->isPublic());
        $verified->reinstate(self::ADMIN, $this->now);
        self::assertSame(ArtisanStatus::Verified, $verified->status());

        $pending = $this->profile();
        $pending->suspend(null, 'Doublon suspecté', $this->now);
        $pending->reinstate(null, $this->now);
        self::assertSame(ArtisanStatus::PendingVerification, $pending->status());
        $events = $pending->releaseEvents();
        self::assertInstanceOf(ArtisanSuspended::class, $events[0]);
        self::assertInstanceOf(ArtisanReinstated::class, $events[1]);
    }

    /** @return iterable<string, array{\Closure(ArtisanProfile, string, \DateTimeImmutable): void, bool}> */
    public static function forbiddenTransitions(): iterable
    {
        yield 'valider un dossier incomplet' => [static fn (ArtisanProfile $p, string $a, \DateTimeImmutable $n) => $p->approve($a, $n), false];
        yield 'refuser un dossier incomplet' => [static fn (ArtisanProfile $p, string $a, \DateTimeImmutable $n) => $p->reject($a, RejectionReason::DuplicateAccount, null, $n), false];
        yield 'suspendre un dossier incomplet' => [static fn (ArtisanProfile $p, string $a, \DateTimeImmutable $n) => $p->suspend($a, 'x', $n), false];
        yield 'rétablir un compte non suspendu' => [static fn (ArtisanProfile $p, string $a, \DateTimeImmutable $n) => $p->reinstate($a, $n), true];
        yield 'valider deux fois' => [static function (ArtisanProfile $p, string $a, \DateTimeImmutable $n): void {
            $p->approve($a, $n);
            $p->approve($a, $n);
        }, true];
    }

    /** @param \Closure(ArtisanProfile, string, \DateTimeImmutable): void $transition */
    #[\PHPUnit\Framework\Attributes\DataProvider('forbiddenTransitions')]
    public function testForbiddenTransitions(\Closure $transition, bool $submitted): void
    {
        $this->expectException(ArtisanRuleViolation::class);
        $transition($this->profile($submitted), self::ADMIN, $this->now);
    }

    public function testVerifiedProfileKeepsItsZoneEditableButNotTheTunnel(): void
    {
        $p = $this->profile();
        $p->approve(self::ADMIN, $this->now);
        $p->defineServiceArea(ServiceArea::list(['76', '14'], []), $this->now);
        self::assertSame(['14', '76'], $p->serviceArea()?->departments, 'RG-042 : modifiable à tout moment');

        $this->expectException(ArtisanRuleViolation::class);
        $p->chooseJobs([new JobId('0190f5e4-0000-7000-8000-000000000c12')], new JobId('0190f5e4-0000-7000-8000-000000000c12'), 5, $this->now);
    }

    public function testCompletionIsAWeightedSumWithProratedPhotos(): void
    {
        $c = ProfileCompletion::compute(self::WEIGHTS, logo: true, description: true, photos: 2, photosTarget: 5, hours: false, zone: true, services: 0, labels: true);

        self::assertSame(63, $c->percent, '0,15 + 0,15 + 0,20 × 2/5 + 0,15 + 0,10');
        self::assertSame(['photos', 'services', 'hours'], $c->missing(), 'du plus au moins pondéré');
        self::assertTrue($c->isDone('zone'));
        self::assertSame(100, ProfileCompletion::compute(self::WEIGHTS, true, true, 9, 5, true, true, 3, true)->percent, 'photos plafonnées à l’objectif');
        self::assertSame(0, ProfileCompletion::compute(self::WEIGHTS, false, false, 0, 5, false, false, 0, false)->percent);
    }

    public function testCompletionIsRecordedOnTheProfile(): void
    {
        $p = $this->profile();
        $p->recordCompletion(ProfileCompletion::compute(self::WEIGHTS, false, true, 0, 5, false, true, 0, false));
        self::assertSame(30, $p->completionRate());
    }

    public function testHidingRemovesVisibilityWithoutSuspending(): void
    {
        $p = $this->profile();
        $p->hide('Assurance expirée', $this->now);
        self::assertFalse($p->isHidden(), 'seul un compte vérifié peut être masqué');

        $p->approve(self::ADMIN, $this->now);
        $p->releaseEvents();
        $p->hide('Assurance obligatoire expirée depuis le 01/10/2026', $this->now);
        self::assertTrue($p->isHidden());
        self::assertFalse($p->isPublic() || $p->canSendQuotes());
        self::assertSame(ArtisanStatus::Verified, $p->status());
        $p->hide('bis', $this->now);
        self::assertCount(1, $p->releaseEvents(), 'masquage idempotent');

        $p->unhide($this->now);
        self::assertTrue($p->isPublic());
    }

    public function testPieceExpiresAfterItsEndDateOnly(): void
    {
        $document = \App\ArtisanProfile\Domain\Document\ArtisanDocument::upload('0190f5e4-0000-7000-8000-000000000d01', '0190f5e4-0000-7000-8000-000000000c02', 'insurance_rc', null, '0190f5e4-0000-7000-8000-000000000f01', null, new \DateTimeImmutable('2026-11-13'), $this->now);
        $document->markScanned($this->now);
        self::assertFalse($document->expire($this->now), 'valable le jour de l’échéance');
        self::assertTrue($document->expire($this->now->modify('+1 day')));
        self::assertSame(\App\ArtisanProfile\Domain\Document\DocumentStatus::Expired, $document->status());
    }
}
