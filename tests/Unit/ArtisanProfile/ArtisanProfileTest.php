<?php

declare(strict_types=1);

namespace App\Tests\Unit\ArtisanProfile;

use App\ArtisanProfile\Domain\Profile\ArtisanProfile;
use App\ArtisanProfile\Domain\Profile\ArtisanProfileId;
use App\ArtisanProfile\Domain\Profile\ArtisanRuleViolation;
use App\ArtisanProfile\Domain\Profile\ArtisanStatus;
use App\ArtisanProfile\Domain\Profile\CompanyDetails;
use App\ArtisanProfile\Domain\Profile\VatStatus;
use App\Catalog\Domain\Job\JobId;
use App\Shared\Domain\ValueObject\Siret;
use PHPUnit\Framework\TestCase;

/** A03 / RG-041 / RG-043 : profil incomplet, entreprise, métiers (1 à pro.max_jobs, un principal), TVA. */
final class ArtisanProfileTest extends TestCase
{
    private const string USER = '0190f5e4-0000-7000-8000-000000000c01';
    private const string J1 = '0190f5e4-0000-7000-8000-000000000c11';
    private const string J2 = '0190f5e4-0000-7000-8000-000000000c12';

    private function profile(): ArtisanProfile
    {
        return ArtisanProfile::start(new ArtisanProfileId('0190f5e4-0000-7000-8000-000000000c02'), self::USER, new \DateTimeImmutable());
    }

    private static function company(VatStatus $vat = VatStatus::Liable, ?string $vatNumber = 'FR68900000001'): CompanyDetails
    {
        return new CompanyDetails(new Siret('90000000100017'), '  Plomberie   Fictive ', null, '5499', '43.22A', $vatNumber, $vat, null, '14000', 'Caen', '14118', null, true);
    }

    public function testFrenchVatNumberIsComputedFromTheSiren(): void
    {
        self::assertSame('FR68900000001', CompanyDetails::frenchVatNumber(new Siret('90000000100017')));
    }

    public function testCompanyThenJobsCompleteTheFirstSteps(): void
    {
        $profile = $this->profile();
        self::assertSame([ArtisanStatus::Incomplete, 1], [$profile->status(), $profile->completedStep()]);

        $profile->declareCompany(self::company(), new \DateTimeImmutable());
        self::assertSame(['Plomberie Fictive', 2, true], [$profile->companyName(), $profile->completedStep(), $profile->isSiretVerified()]);

        $profile->chooseJobs([new JobId(self::J1), new JobId(self::J2), new JobId(self::J1)], new JobId(self::J2), 5, new \DateTimeImmutable());
        self::assertSame(3, $profile->completedStep());
        self::assertCount(2, $profile->jobIds(), 'doublons ignorés');
        self::assertSame(self::J2, $profile->mainJobId()?->value);
        self::assertCount(3, $profile->releaseEvents());
    }

    /** @return iterable<string, array{list<string>, string, int, string}> */
    public static function invalidJobChoices(): iterable
    {
        yield 'aucun' => [[], self::J1, 5, 'au moins un métier'];
        yield 'trop' => [[self::J1, self::J2], self::J1, 1, 'au maximum'];
        yield 'principal hors liste' => [[self::J1], self::J2, 5, 'métier principal'];
    }

    /** @param list<string> $jobs */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidJobChoices')]
    public function testJobRules(array $jobs, string $main, int $max, string $message): void
    {
        $profile = $this->profile();
        $profile->declareCompany(self::company(), new \DateTimeImmutable());

        $this->expectException(ArtisanRuleViolation::class);
        $this->expectExceptionMessage($message);
        $profile->chooseJobs(array_map(static fn (string $j): JobId => new JobId($j), $jobs), new JobId($main), $max, new \DateTimeImmutable());
    }

    public function testJobsRequireTheCompanyFirst(): void
    {
        $this->expectException(ArtisanRuleViolation::class);
        $this->profile()->chooseJobs([new JobId(self::J1)], new JobId(self::J1), 5, new \DateTimeImmutable());
    }

    public function testLiableCompanyNeedsAVatNumberButFranchiseDoesNot(): void
    {
        self::assertNull(self::company(VatStatus::Franchise, null)->vatNumber);

        $this->expectException(ArtisanRuleViolation::class);
        self::company(VatStatus::Liable, null);
    }
}
