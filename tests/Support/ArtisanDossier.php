<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\ArtisanProfile\Application\EventHandler\TrackDocumentScan;
use App\ArtisanProfile\Application\Signup\ChooseJobs;
use App\ArtisanProfile\Application\Signup\CompleteDocumentsStep;
use App\ArtisanProfile\Application\Signup\DeclareCompany;
use App\ArtisanProfile\Application\Signup\DeclareInsurances;
use App\ArtisanProfile\Application\Signup\DefineServiceArea;
use App\ArtisanProfile\Application\Signup\SubmitSignup;
use App\ArtisanProfile\Application\Signup\UploadDocument;
use App\Catalog\Application\Command\CreateCategory;
use App\Catalog\Application\Command\CreateJob;
use App\Catalog\Application\Geo\GeoReferentialImporter;
use App\Catalog\Application\Geo\Port\GeoReferentialSourceFactory;
use App\Identity\Application\Legal\PublishLegalDocument;
use App\Identity\Application\Registration\RegisterArtisanAccount;
use App\Shared\Application\Bus\Command;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Storage\ScanQuarantinedFile;
use App\Shared\Domain\Storage\FileAvailable;
use App\Shared\Domain\Storage\FileQuarantined;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\Fake\FakeCompanyRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Dossier artisan construit par les cas d'usage (sans passer par les écrans) : compte, entreprise (SIRET fictif), métier
 * « Plombier » (Kbis + RC Pro), zone, RC Pro, pièce d'identité, envoi. Pièces analysées par l'antivirus de test.
 */
trait ArtisanDossier
{
    private const string DOSSIER_USER = '0190f5e4-0000-7000-8000-0000000b0001';
    private const array DOSSIER_LEGAL = ['0190f5e4-0000-7000-8000-0000000d0010', '0190f5e4-0000-7000-8000-0000000d0011', '0190f5e4-0000-7000-8000-0000000d0012'];

    abstract protected static function getContainer(): ContainerInterface;

    private function dossierDispatch(Command $command): void
    {
        $bus = self::getContainer()->get(CommandBus::class);
        \assert($bus instanceof CommandBus);
        $bus->dispatch($command);
    }

    private function dossierValue(string $sql): string
    {
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $value = $connection->fetchOne($sql);

        return \is_int($value) || \is_bool($value) ? var_export($value, true) : DbalValue::string($value);
    }

    private function prepareReferentials(): void
    {
        $importer = self::getContainer()->get(GeoReferentialImporter::class);
        $sources = self::getContainer()->get(GeoReferentialSourceFactory::class);
        \assert($importer instanceof GeoReferentialImporter && $sources instanceof GeoReferentialSourceFactory);
        foreach (['cgu', 'cgv_pro', 'privacy'] as $i => $type) {
            $this->dossierDispatch(new PublishLegalDocument(self::DOSSIER_LEGAL[$i], $type, $type, '<p>Texte fictif de test</p>', false));
        }
        $this->dossierDispatch(new CreateCategory('Plomberie', 'wrench'));
        $this->dossierDispatch(new CreateJob('plomberie', 'Plombier', requiredDocuments: ['kbis', 'insurance_rc']));
        $importer->import($sources->file(__DIR__.'/../fixtures/geo/normandie.json'), false);
    }

    /** Dossier envoyé avec la seule pièce d'identité ; RC Pro valable `$rcDays` jours. Renvoie l'id du profil. */
    private function submittedDossier(int $rcDays = 20): string
    {
        $job = $this->dossierValue("SELECT id FROM catalog.job WHERE slug = 'plombier'");
        $this->dossierDispatch(new RegisterArtisanAccount(self::DOSSIER_USER, 'dominique.verif@exemple.test', 'cheval agrafe batterie', 'Dominique', 'Exemple', '06 11 22 33 44', self::DOSSIER_LEGAL));
        $this->dossierDispatch(new DeclareCompany(self::DOSSIER_USER, FakeCompanyRegistry::ACTIVE, 'Atelier Fictif', 'FR68900000001', 'assujetti', '', '', ''));
        $this->dossierDispatch(new ChooseJobs(self::DOSSIER_USER, [$job], $job));
        $this->dossierDispatch(new DefineServiceArea(self::DOSSIER_USER, 'radius', '76341', 30));
        $this->dossierDispatch(new DeclareInsurances(self::DOSSIER_USER, [['kind' => 'rc_pro', 'insurer' => 'MAAF', 'policy' => 'RC-1', 'valid_until' => new \DateTimeImmutable(\sprintf('+%d days', $rcDays))->format('Y-m-d')]], []));
        $this->uploadPiece('identity');
        $this->dossierDispatch(new CompleteDocumentsStep(self::DOSSIER_USER, true));
        $this->dossierDispatch(new SubmitSignup(self::DOSSIER_USER, 'Plombier au Havre (compte fictif de test).'));

        return $this->dossierValue('SELECT id FROM artisan.artisan_profile');
    }

    /** Dépose et fait analyser une pièce ; renvoie l'id du justificatif. */
    private function uploadPiece(string $slot): string
    {
        $this->dossierDispatch(new UploadDocument((string) Uuid::v7(), self::DOSSIER_USER, $slot, StorageSamples::file(StorageSamples::pdf()), $slot.'.pdf'));
        $fileId = $this->dossierValue("SELECT file_id FROM artisan.document WHERE type = '".$slot."' AND status <> 'shredded' ORDER BY uploaded_at DESC LIMIT 1");
        $scan = self::getContainer()->get(ScanQuarantinedFile::class);
        $track = self::getContainer()->get(TrackDocumentScan::class);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($scan instanceof ScanQuarantinedFile && $track instanceof TrackDocumentScan && $em instanceof EntityManagerInterface);
        $scan(new FileQuarantined($fileId, 'artisan_document', new \DateTimeImmutable()));
        $track(new FileAvailable($fileId, 'artisan_document', new \DateTimeImmutable()));
        $em->flush();

        return $this->dossierValue("SELECT id FROM artisan.document WHERE file_id = '".$fileId."'");
    }
}
