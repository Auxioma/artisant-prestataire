<?php

declare(strict_types=1);

namespace App\Tests\Concurrency;

use App\ArtisanProfile\Application\EventHandler\TrackDocumentScan;
use App\ArtisanProfile\Application\Signup\ChooseJobs;
use App\ArtisanProfile\Application\Signup\CompleteDocumentsStep;
use App\ArtisanProfile\Application\Signup\DeclareCompany;
use App\ArtisanProfile\Application\Signup\DeclareInsurances;
use App\ArtisanProfile\Application\Signup\DefineServiceArea;
use App\ArtisanProfile\Application\Signup\SubmitSignup;
use App\ArtisanProfile\Application\Signup\UploadDocument;
use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\Billing\Application\Credit\CreditLedger;
use App\Identity\Application\Command\RegisterUser;
use App\Identity\Application\Registration\RegisterArtisanAccount;
use App\Quote\Application\Editor\SaveQuoteDraft;
use App\QuoteRequest\Application\Dispatch\DispatchQuoteRequest;
use App\QuoteRequest\Application\Submission\SubmitQuoteRequest;
use App\Shared\Application\Storage\ScanQuarantinedFile;
use App\Shared\Domain\Storage\FileAvailable;
use App\Shared\Domain\Storage\FileQuarantined;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\Fake\FakeCompanyRegistry;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Données **commitées** du banc de concurrence (base dédiée `…_test_concurrency`, jamais la base de test partagée) :
 * trois artisans vérifiés (fictifs), un client, quatre demandes diffusées et des brouillons complets.
 * - A (2 crédits) : un brouillon sur chacune des demandes 1 à 3 (double clic, puis dernier crédit) ;
 * - B et C (1 crédit chacun) : un brouillon sur la demande 4, qui n'a plus qu'une place (dernière place).
 */
final class ConcurrencyFixture
{
    use ArtisanDossier;

    public const string CLIENT = '0190f5e4-0000-7000-8000-0000000cc001';
    /** Second client : la demande 4 (sinon retenue en modération, 4ᵉ demande proche du même client). */
    public const string CLIENT_BIS = '0190f5e4-0000-7000-8000-0000000cc002';
    public const string ADMIN = '0190f5e4-0000-7000-8000-0000000cca01';
    /** Comptes artisans A, B, C. */
    public const array USERS = ['0190f5e4-0000-7000-8000-0000000cc011', '0190f5e4-0000-7000-8000-0000000cc012', '0190f5e4-0000-7000-8000-0000000cc013'];
    public const array REQUESTS = ['0190f5e4-0000-7000-8000-0000000cc101', '0190f5e4-0000-7000-8000-0000000cc102', '0190f5e4-0000-7000-8000-0000000cc103', '0190f5e4-0000-7000-8000-0000000cc104'];
    /** Brouillons de A sur les demandes 1 à 3, puis de B et C sur la demande 4. */
    public const array QUOTES = ['0190f5e4-0000-7000-8000-0000000cc201', '0190f5e4-0000-7000-8000-0000000cc202', '0190f5e4-0000-7000-8000-0000000cc203', '0190f5e4-0000-7000-8000-0000000cc204', '0190f5e4-0000-7000-8000-0000000cc205'];
    public const int CREDITS = 2;

    private const array SIRETS = [FakeCompanyRegistry::ACTIVE, FakeCompanyRegistry::ACTIVE_BIS, FakeCompanyRegistry::ACTIVE_TER];

    private static ContainerInterface $container;

    /** @return list<string> ids des profils A, B, C */
    public static function build(ContainerInterface $container): array
    {
        self::$container = $container;

        return new self()->load();
    }

    protected static function getContainer(): ContainerInterface
    {
        return self::$container;
    }

    /** @return list<string> */
    private function load(): array
    {
        $connection = self::getContainer()->get(Connection::class);
        $ledger = self::getContainer()->get(CreditLedger::class);
        \assert($connection instanceof Connection && $ledger instanceof CreditLedger);
        $this->prepareReferentials();
        $job = $this->dossierValue("SELECT id FROM catalog.job WHERE slug = 'plombier'");
        $artisans = [];
        foreach (self::USERS as $i => $user) {
            $artisans[] = $artisan = $this->artisan($i, $user, $job);
            $ledger->grantFree(\sprintf('0190f5e4-0000-7000-8000-0000000cc3%02d', $i), $artisan, $user, 0 === $i ? self::CREDITS : 1, null, new \DateTimeImmutable());
        }
        $this->dossierDispatch(new RegisterUser(self::CLIENT, 'camille.concurrence@exemple.test', 'cheval agrafe batterie', 'client', 'Camille', 'Concurrence'));
        $this->dossierDispatch(new RegisterUser(self::CLIENT_BIS, 'alex.concurrence@exemple.test', 'cheval agrafe batterie', 'client', 'Alex', 'Concurrence'));
        foreach (self::REQUESTS as $i => $request) {
            $last = 3 === $i;
            $this->dossierDispatch(new SubmitQuoteRequest($request, $last ? self::CLIENT_BIS : self::CLIENT, $job, '76351', '76600', ($i + 1).' rue Fictive', 'repair', $last ? 'Chauffe-eau électrique qui ne chauffe plus du tout (fictif).' : 'Fuite sous l’évier de la cuisine depuis ce matin (fictif).', [], null, 'week', null, title: $last ? 'Chauffe-eau' : 'Fuite n° '.($i + 1)));
            $this->dossierDispatch(new DispatchQuoteRequest($request));
        }
        $connection->executeStatement('UPDATE request.quote_request SET max_quotes = 1 WHERE id = :id', ['id' => self::REQUESTS[3]]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        foreach ([[0, 0], [0, 1], [0, 2], [1, 3], [2, 3]] as $q => [$artisan, $request]) {
            $this->dossierDispatch(new SaveQuoteDraft(self::USERS[$artisan], self::REQUESTS[$request], self::QUOTES[$q], [
                'subject' => 'Réparation de la fuite (fictif)',
                'lines' => [
                    ['type' => 'labour', 'label' => 'Recherche de fuite', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '90', 'vat' => '1000'],
                    ['type' => 'travel', 'label' => 'Déplacement', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '0', 'vat' => '2000'],
                ],
            ]));
        }

        return $artisans;
    }

    /** Artisan fictif vérifié (Le Havre, plombier, RC Pro valable), compte actif ; renvoie l'id du profil. */
    private function artisan(int $i, string $user, string $job): string
    {
        $this->dossierDispatch(new RegisterArtisanAccount($user, \sprintf('artisan.%d.concurrence@exemple.test', $i + 1), 'cheval agrafe batterie', 'Dominique', 'Exemple', \sprintf('06 11 22 33 %02d', 40 + $i), self::DOSSIER_LEGAL));
        $this->dossierDispatch(new DeclareCompany($user, self::SIRETS[$i], 'Atelier Fictif '.($i + 1), '', 'assujetti', '', '', ''));
        $this->dossierDispatch(new ChooseJobs($user, [$job], $job));
        $this->dossierDispatch(new DefineServiceArea($user, 'radius', '76341', 30));
        $this->dossierDispatch(new DeclareInsurances($user, [['kind' => 'rc_pro', 'insurer' => 'MAAF', 'policy' => 'RC-'.($i + 1), 'valid_until' => new \DateTimeImmutable('+200 days')->format('Y-m-d')]], []));
        $this->upload($user, 'identity');
        $this->dossierDispatch(new CompleteDocumentsStep($user, true));
        $this->dossierDispatch(new SubmitSignup($user, 'Plombier au Havre (compte fictif de test).'));
        $artisan = $this->dossierValue("SELECT id FROM artisan.artisan_profile WHERE user_id = '".$user."'");
        $this->upload($user, 'kbis');
        $this->upload($user, 'insurance_rc');
        $this->dossierDispatch(new ApproveArtisan($artisan, self::ADMIN));
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $connection->executeStatement("UPDATE identity.user_account SET status = 'active', email_verified_at = date_trunc('second', now()) WHERE id = :id", ['id' => $user]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return $artisan;
    }

    private function upload(string $user, string $slot): void
    {
        $this->dossierDispatch(new UploadDocument((string) Uuid::v7(), $user, $slot, StorageSamples::file(StorageSamples::pdf()), $slot.'.pdf'));
        $fileId = $this->dossierValue("SELECT d.file_id FROM artisan.document d JOIN artisan.artisan_profile p ON p.id = d.artisan_id WHERE p.user_id = '".$user."' AND d.type = '".$slot."' AND d.status <> 'shredded' ORDER BY d.uploaded_at DESC LIMIT 1");
        $scan = self::getContainer()->get(ScanQuarantinedFile::class);
        $track = self::getContainer()->get(TrackDocumentScan::class);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($scan instanceof ScanQuarantinedFile && $track instanceof TrackDocumentScan && $em instanceof EntityManagerInterface);
        $scan(new FileQuarantined($fileId, 'artisan_document', new \DateTimeImmutable()));
        $track(new FileAvailable($fileId, 'artisan_document', new \DateTimeImmutable()));
        $em->flush();
    }
}
