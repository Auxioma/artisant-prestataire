<?php

declare(strict_types=1);

namespace App\Tests\Unit\ArtisanProfile;

use App\ArtisanProfile\Domain\Document\ArtisanDocument;
use App\ArtisanProfile\Domain\Document\DocumentStatus;
use App\ArtisanProfile\Domain\Profile\ArtisanProfile;
use App\ArtisanProfile\Domain\Profile\ArtisanProfileId;
use App\ArtisanProfile\Domain\Profile\ArtisanRuleViolation;
use App\ArtisanProfile\Domain\Profile\ArtisanStatus;
use App\ArtisanProfile\Domain\Profile\CompanyDetails;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanSignupSubmitted;
use App\ArtisanProfile\Domain\Profile\Insurance;
use App\ArtisanProfile\Domain\Profile\InsuranceKind;
use App\ArtisanProfile\Domain\Profile\InsuranceRequirements;
use App\ArtisanProfile\Domain\Profile\Qualification;
use App\ArtisanProfile\Domain\Profile\ServiceArea;
use App\ArtisanProfile\Domain\Profile\ServiceAreaMode;
use App\ArtisanProfile\Domain\Profile\VatStatus;
use App\Catalog\Domain\Job\JobId;
use App\Shared\Domain\ValueObject\Siret;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A03.4 à A03.7 : zone (RG-042, bornes paramétrées), assurances (date de fin ≥ aujourd'hui), justificatifs, envoi (RG-043). */
final class ArtisanSignupStepsTest extends TestCase
{
    private const string USER = '0190f5e4-0000-7000-8000-000000000c01';
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-13 10:00:00');
    }

    private function profileAtStep(int $step): ArtisanProfile
    {
        $profile = ArtisanProfile::start(new ArtisanProfileId('0190f5e4-0000-7000-8000-000000000c02'), self::USER, $this->now);
        $profile->declareCompany(new CompanyDetails(new Siret('90000000100017'), 'Plomberie Fictive', 'Atelier Fictif', null, null, null, VatStatus::Franchise, null, '76600', 'Le Havre', null, null, true), $this->now);
        $profile->chooseJobs([new JobId('0190f5e4-0000-7000-8000-000000000c11')], new JobId('0190f5e4-0000-7000-8000-000000000c11'), 5, $this->now);
        if ($step >= 4) {
            $profile->defineServiceArea(ServiceArea::radius('76341', 30, 5, 150), $this->now);
        }
        if ($step >= 5) {
            $profile->declareInsurances([$this->insurance(InsuranceKind::ProfessionalLiability)], [], new InsuranceRequirements(true, false, true), $this->now);
        }
        if ($step >= 6) {
            $profile->completeDocumentsStep($this->now);
        }
        $profile->releaseEvents();

        return $profile;
    }

    private function insurance(InsuranceKind $kind, string $until = '2027-12-31'): Insurance
    {
        return new Insurance($kind, ' AXA   France ', '4521-889-22', new \DateTimeImmutable($until));
    }

    public function testRadiusAreaWithinConfiguredBounds(): void
    {
        $area = ServiceArea::radius('76341', 30, 5, 150);
        self::assertSame([ServiceAreaMode::Radius, '76341', 30], [$area->mode, $area->centerInsee, $area->radiusKm]);

        foreach ([4, 151] as $km) {
            try {
                ServiceArea::radius('76341', $km, 5, 150);
                self::fail('Rayon hors bornes accepté : '.$km);
            } catch (ArtisanRuleViolation $e) {
                self::assertStringContainsString('entre 5 et 150 km', $e->getMessage());
            }
        }
    }

    public function testListAreaNeedsAtLeastOneValidZone(): void
    {
        $area = ServiceArea::list(['76', '14', '76'], ['75056']);
        self::assertSame([['14', '76'], ['75056']], [$area->departments, $area->communes]);

        $this->expectExceptionMessage('Définissez votre zone d’intervention.');
        ServiceArea::list([], []);
    }

    public function testListAreaRejectsMalformedCodes(): void
    {
        $this->expectException(ArtisanRuleViolation::class);
        ServiceArea::list(['ZZ'], []);
    }

    public function testAreaComesAfterJobsAndIsStored(): void
    {
        $profile = $this->profileAtStep(3);
        $profile->defineServiceArea(ServiceArea::list(['76'], []), $this->now);

        self::assertSame(4, $profile->completedStep());
        self::assertSame(['76'], $profile->serviceArea()?->departments);
    }

    public function testInsurancesFollowJobRequirements(): void
    {
        $profile = $this->profileAtStep(4);
        try {
            $profile->declareInsurances([$this->insurance(InsuranceKind::ProfessionalLiability)], [], new InsuranceRequirements(true, true, true), $this->now);
            self::fail('Décennale exigée mais absente');
        } catch (ArtisanRuleViolation $e) {
            self::assertSame('Assurance décennale obligatoire pour vos métiers.', $e->getMessage());
        }
        try {
            $profile->declareInsurances([], [], new InsuranceRequirements(true, false, false), $this->now);
            self::fail('Aucune assurance alors qu’une est exigée');
        } catch (ArtisanRuleViolation $e) {
            self::assertStringContainsString('au moins une assurance', $e->getMessage());
        }

        $profile->declareInsurances(
            [$this->insurance(InsuranceKind::Decennial), $this->insurance(InsuranceKind::ProfessionalLiability)],
            [new Qualification('RGE QualiPAC', 'E-12345', new \DateTimeImmutable('2027-03-31'))],
            new InsuranceRequirements(true, true, true),
            $this->now,
        );
        self::assertSame(5, $profile->completedStep());
        self::assertSame('AXA France', $profile->insurances()[0]->insurer, 'espaces normalisés');
        self::assertSame('RGE QualiPAC', $profile->qualifications()[0]->name);
    }

    /** @return iterable<string, array{list<Insurance>, list<Qualification>, string}> */
    public static function invalidDeclarations(): iterable
    {
        $until = new \DateTimeImmutable('2027-01-01');
        yield 'assurance expirée' => [[new Insurance(InsuranceKind::ProfessionalLiability, 'MAAF', 'P-1', new \DateTimeImmutable('2026-11-12'))], [], 'Votre attestation d’assurance semble expirée.'];
        yield 'deux RC Pro' => [[new Insurance(InsuranceKind::ProfessionalLiability, 'MAAF', 'P-1', $until), new Insurance(InsuranceKind::ProfessionalLiability, 'AXA', 'P-2', $until)], [], 'déclarée deux fois'];
        yield 'label expiré' => [[], [new Qualification('Qualibat', 'Q-1', new \DateTimeImmutable('2026-01-01'))], 'semble expiré'];
        yield 'label en double' => [[], [new Qualification('Qualibat', 'Q-1', $until), new Qualification('QUALIBAT', 'Q-2', $until)], 'déclaré deux fois'];
    }

    /**
     * @param list<Insurance>     $insurances
     * @param list<Qualification> $qualifications
     */
    #[DataProvider('invalidDeclarations')]
    public function testInvalidDeclarationsAreRefused(array $insurances, array $qualifications, string $message): void
    {
        $this->expectExceptionMessage($message);
        $this->profileAtStep(4)->declareInsurances($insurances, $qualifications, new InsuranceRequirements(false, false, false), $this->now);
    }

    public function testInsuranceValidUntilTodayIsStillValid(): void
    {
        $profile = $this->profileAtStep(4);
        $profile->declareInsurances([$this->insurance(InsuranceKind::ProfessionalLiability, '2026-11-13')], [], new InsuranceRequirements(true, false, true), $this->now);
        self::assertSame(5, $profile->completedStep());
    }

    public function testStepsCannotBeSkipped(): void
    {
        $profile = $this->profileAtStep(3);
        $this->expectExceptionMessage('Définissez d’abord votre zone d’intervention.');
        $profile->declareInsurances([], [], new InsuranceRequirements(false, false, false), $this->now);
    }

    public function testSubmissionMovesToPendingVerificationOnce(): void
    {
        $profile = $this->profileAtStep(6);
        $profile->submit("  Plombier chauffagiste au Havre.\r\n\r\n\r\nDépannage   et chauffe-eau. ", $this->now);

        self::assertSame([ArtisanStatus::PendingVerification, 7], [$profile->status(), $profile->completedStep()]);
        self::assertSame("Plombier chauffagiste au Havre.\n\nDépannage et chauffe-eau.", $profile->shortDescription());
        $events = $profile->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(ArtisanSignupSubmitted::class, $events[0]);

        $profile->submit('Nouvelle description', $this->now);
        self::assertSame([], $profile->releaseEvents(), 'un seul dossier envoyé');
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidDescriptions(): iterable
    {
        yield 'vide' => ['   ', 'quelques mots'];
        yield 'balise' => ['Plombier <script>alert(1)</script>', 'balises HTML'];
        yield 'trop longue' => [str_repeat('a', 301), '300 caractères'];
    }

    #[DataProvider('invalidDescriptions')]
    public function testDescriptionRules(string $description, string $message): void
    {
        $this->expectExceptionMessage($message);
        $this->profileAtStep(6)->submit($description, $this->now);
    }

    public function testDocumentLifecycleFollowsTheAntivirus(): void
    {
        $document = ArtisanDocument::upload('0190f5e4-0000-7000-8000-000000000d01', '0190f5e4-0000-7000-8000-000000000c02', 'kbis', null, '0190f5e4-0000-7000-8000-000000000f01', 'kbis.pdf', null, $this->now);
        self::assertSame(DocumentStatus::Quarantined, $document->status());
        self::assertTrue($document->status()->counts());

        $document->markScanned($this->now);
        self::assertSame(DocumentStatus::PendingReview, $document->status());
        $document->markRejectedByScan($this->now);
        self::assertSame(DocumentStatus::PendingReview, $document->status(), 'un fichier déjà jugé sain ne change plus');

        $document->supersede($this->now);
        self::assertFalse($document->status()->counts());
    }

    public function testDocumentTypeIsValidated(): void
    {
        $this->expectException(ArtisanRuleViolation::class);
        ArtisanDocument::upload('0190f5e4-0000-7000-8000-000000000d01', '0190f5e4-0000-7000-8000-000000000c02', '../kbis', null, '0190f5e4-0000-7000-8000-000000000f01', null, null, $this->now);
    }

    public function testDocumentReviewIsAttributedAndReasoned(): void
    {
        $document = ArtisanDocument::upload('0190f5e4-0000-7000-8000-000000000d01', '0190f5e4-0000-7000-8000-000000000c02', 'kbis', null, '0190f5e4-0000-7000-8000-000000000f01', 'kbis.pdf', null, $this->now);
        try {
            $document->approve('0190f5e4-0000-7000-8000-00000000ad01', $this->now);
            self::fail('Pièce validée avant l’analyse antivirus');
        } catch (ArtisanRuleViolation) {
        }
        $document->markScanned($this->now);
        try {
            $document->reject('0190f5e4-0000-7000-8000-00000000ad01', '   ', $this->now);
            self::fail('Refus sans motif');
        } catch (ArtisanRuleViolation) {
        }

        $document->reject('0190f5e4-0000-7000-8000-00000000ad01', 'Kbis de plus de 3 mois', $this->now);

        self::assertSame([DocumentStatus::Rejected, 'Kbis de plus de 3 mois'], [$document->status(), $document->reason()]);
        self::assertEquals($this->now, $document->reviewedAt());
        $events = $document->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(\App\ArtisanProfile\Domain\Document\Event\ArtisanDocumentReviewed::class, $events[0]);
    }
}
