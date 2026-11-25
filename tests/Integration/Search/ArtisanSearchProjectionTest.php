<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\ArtisanProfile\Application\Verification\SuspendArtisan;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanSuspended;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanVerified;
use App\Search\Application\EventHandler\ProjectArtisanForSearch;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\StorageSamples;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** La projection de recherche suit les événements du profil : seuls les artisans publics y figurent (RG-043, RG-130). */
final class ArtisanSearchProjectionTest extends KernelTestCase
{
    use ArtisanDossier;

    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a0001';

    protected function setUp(): void
    {
        self::bootKernel();
        $this->prepareReferentials();
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    public function testVerifiedArtisanIsIndexedAndRemovedWhenSuspended(): void
    {
        $profile = $this->submittedDossier();
        $project = self::getContainer()->get(ProjectArtisanForSearch::class);
        \assert($project instanceof ProjectArtisanForSearch);

        $project(new ArtisanVerified($profile, self::DOSSIER_USER, self::ADMIN, new \DateTimeImmutable()));
        self::assertSame('0', $this->dossierValue('SELECT count(*) FROM search.artisan_search_view'), 'dossier en attente : pas public');

        $this->uploadPiece('kbis');
        $this->uploadPiece('insurance_rc');
        $this->dossierDispatch(new ApproveArtisan($profile, self::ADMIN));
        $project(new ArtisanVerified($profile, self::DOSSIER_USER, self::ADMIN, new \DateTimeImmutable()));
        self::assertSame('Atelier Fictif|Caen|14|1', $this->dossierValue("SELECT name || '|' || commune_name || '|' || department_code || '|' || array_length(job_ids, 1) FROM search.artisan_search_view"), 'position : centroïde de la commune du siège (RG-022)');

        $this->dossierDispatch(new SuspendArtisan($profile, self::ADMIN, 'Contrôle'));
        $project(new ArtisanSuspended($profile, self::DOSSIER_USER, self::ADMIN, 'Contrôle', new \DateTimeImmutable()));
        self::assertSame('0', $this->dossierValue('SELECT count(*) FROM search.artisan_search_view'));
    }
}
