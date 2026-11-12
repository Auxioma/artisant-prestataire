<?php

declare(strict_types=1);

namespace App\Tests\Functional\ArtisanProfile;

use App\ArtisanProfile\Application\EventHandler\SendVerificationOutcome;
use App\ArtisanProfile\Application\EventHandler\TrackDocumentScan;
use App\ArtisanProfile\Application\Signup\ChooseJobs;
use App\ArtisanProfile\Application\Signup\CompleteDocumentsStep;
use App\ArtisanProfile\Application\Signup\DeclareCompany;
use App\ArtisanProfile\Application\Signup\DeclareInsurances;
use App\ArtisanProfile\Application\Signup\DefineServiceArea;
use App\ArtisanProfile\Application\Signup\SubmitSignup;
use App\ArtisanProfile\Application\Signup\UploadDocument;
use App\ArtisanProfile\Domain\Document\Event\ArtisanDocumentReviewed;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanMoreInfoRequested;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanRejected;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanVerified;
use App\Catalog\Application\Command\CreateCategory;
use App\Catalog\Application\Command\CreateJob;
use App\Catalog\Application\Geo\GeoReferentialImporter;
use App\Catalog\Application\Geo\Port\GeoReferentialSourceFactory;
use App\Identity\Application\Legal\PublishLegalDocument;
use App\Identity\Application\Registration\RegisterArtisanAccount;
use App\Shared\Application\Bus\Command;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Storage\ScanQuarantinedFile;
use App\Shared\Domain\Event\DomainEvent;
use App\Shared\Domain\Storage\FileAvailable;
use App\Shared\Domain\Storage\FileQuarantined;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\AdminSession;
use App\Tests\Support\Fake\FakeCompanyRegistry;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

/** BO03.2 : file de vérification, contrôles, visionneuse journalisée, décisions RG-130, E-21 / E-22 / E-24. */
final class VerificationQueueTest extends WebTestCase
{
    use AdminSession;

    private const string USER = '0190f5e4-0000-7000-8000-0000000b0001';
    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a0001';
    private const array LEGAL = ['0190f5e4-0000-7000-8000-0000000d0010', '0190f5e4-0000-7000-8000-0000000d0011', '0190f5e4-0000-7000-8000-0000000d0012'];

    private KernelBrowser $client;
    private Connection $connection;
    private string $profileId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $container = self::getContainer();
        $connection = $container->get(Connection::class);
        $importer = $container->get(GeoReferentialImporter::class);
        $sources = $container->get(GeoReferentialSourceFactory::class);
        \assert($connection instanceof Connection && $importer instanceof GeoReferentialImporter && $sources instanceof GeoReferentialSourceFactory);
        $this->connection = $connection;
        foreach (['cgu', 'cgv_pro', 'privacy'] as $i => $type) {
            $this->dispatch(new PublishLegalDocument(self::LEGAL[$i], $type, $type, '<p>Texte fictif de test</p>', false));
        }
        $this->dispatch(new CreateCategory('Plomberie', 'wrench'));
        $this->dispatch(new CreateJob('plomberie', 'Plombier', requiredDocuments: ['kbis', 'insurance_rc']));
        $importer->import($sources->file(__DIR__.'/../../fixtures/geo/normandie.json'), false);
        $this->profileId = $this->submittedDossier();
        StorageSamples::cleanup();
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    private function dispatch(Command $command): void
    {
        $bus = self::getContainer()->get(CommandBus::class);
        \assert($bus instanceof CommandBus);
        $bus->dispatch($command);
    }

    private function value(string $sql): string
    {
        $value = $this->connection->fetchOne($sql);

        return \is_int($value) || \is_bool($value) ? var_export($value, true) : DbalValue::string($value);
    }

    /** Dossier envoyé avec la seule pièce d'identité (Kbis et RC Pro manquants). */
    private function submittedDossier(): string
    {
        $this->dispatch(new RegisterArtisanAccount(self::USER, 'dominique.verif@exemple.test', 'cheval agrafe batterie', 'Dominique', 'Exemple', '06 11 22 33 44', self::LEGAL));
        $this->dispatch(new DeclareCompany(self::USER, FakeCompanyRegistry::ACTIVE, 'Atelier Fictif', 'FR68900000001', 'assujetti', '', '', ''));
        $this->dispatch(new ChooseJobs(self::USER, [$this->value("SELECT id FROM catalog.job WHERE slug = 'plombier'")], $this->value("SELECT id FROM catalog.job WHERE slug = 'plombier'")));
        $this->dispatch(new DefineServiceArea(self::USER, 'radius', '76341', 30));
        $this->dispatch(new DeclareInsurances(self::USER, [['kind' => 'rc_pro', 'insurer' => 'MAAF', 'policy' => 'RC-1', 'valid_until' => new \DateTimeImmutable('+20 days')->format('Y-m-d')]], []));
        $this->upload('identity');
        $this->dispatch(new CompleteDocumentsStep(self::USER, true));
        $this->dispatch(new SubmitSignup(self::USER, 'Plombier au Havre (compte fictif de test).'));

        return $this->value('SELECT id FROM artisan.artisan_profile');
    }

    private function upload(string $slot): string
    {
        $this->dispatch(new UploadDocument((string) \Symfony\Component\Uid\Uuid::v7(), self::USER, $slot, StorageSamples::file(StorageSamples::pdf()), $slot.'.pdf'));
        $fileId = $this->value("SELECT file_id FROM artisan.document WHERE type = '".$slot."' AND status <> 'shredded' ORDER BY uploaded_at DESC LIMIT 1");
        $scan = self::getContainer()->get(ScanQuarantinedFile::class);
        $track = self::getContainer()->get(TrackDocumentScan::class);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($scan instanceof ScanQuarantinedFile && $track instanceof TrackDocumentScan && $em instanceof EntityManagerInterface);
        $scan(new FileQuarantined($fileId, 'artisan_document', new \DateTimeImmutable()));
        $track(new FileAvailable($fileId, 'artisan_document', new \DateTimeImmutable()));
        $em->flush();

        return $this->value("SELECT id FROM artisan.document WHERE file_id = '".$fileId."'");
    }

    private function notify(DomainEvent $event): Email
    {
        $handler = self::getContainer()->get(SendVerificationOutcome::class);
        \assert($handler instanceof SendVerificationOutcome && ($event instanceof ArtisanVerified || $event instanceof ArtisanRejected || $event instanceof ArtisanMoreInfoRequested || $event instanceof ArtisanDocumentReviewed));
        $handler($event);
        $mail = self::getMailerMessage();
        \assert($mail instanceof Email);

        return $mail;
    }

    private function token(string $selector): string
    {
        return (string) $this->client->request('GET', '/admin/artisans/a-verifier?dossier='.$this->profileId)->filter($selector)->attr('value');
    }

    private function decide(string $action, string $message = '', string $reason = 'invalid_documents'): void
    {
        $this->client->request('POST', '/admin/artisans/'.$this->profileId.'/decision', ['_token' => $this->token('form.tm-verif__decision input[name="_token"]'), 'action' => $action, 'message' => $message, 'reason' => $reason]);
    }

    public function testQueueShowsTheDossierWithSireneRecallAndAutomaticChecks(): void
    {
        $this->adminSession($this->client, self::ADMIN, 'moderator');

        $crawler = $this->client->request('GET', '/admin/artisans/a-verifier');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.tm-verif__item', 'Atelier Fictif');
        self::assertSelectorTextContains('.tm-admin__badge', '1');
        $text = $crawler->filter('.tm-verif__dossier')->text();
        self::assertStringContainsString('PLOMBERIE FICTIVE DE TEST', $text, 'raison sociale rappelée depuis Sirene');
        self::assertStringContainsString('Dirigeant : Dominique Exemple', $text);
        self::assertStringContainsString('RC Professionnelle : expire dans', $text, 'échéance proche signalée');
        self::assertStringContainsString('Pièces manquantes ou refusées : Extrait Kbis', $text);
        self::assertStringContainsString('Aucun doublon de téléphone ni d’appareil', $text);
        self::assertCount(1, $crawler->filter('.tm-verif__check--ok:contains("Cohérence nom / SIRET")'));
    }

    public function testDocumentViewingIsLoggedAndScopedToTheDossier(): void
    {
        $this->adminSession($this->client, self::ADMIN, 'moderator');
        $document = $this->value("SELECT id FROM artisan.document WHERE type = 'identity'");

        $crawler = $this->client->request('GET', '/admin/artisans/a-verifier?dossier='.$this->profileId.'&voir[]='.$document);
        self::assertCount(1, $crawler->filter('.tm-verif__viewer iframe'));
        $this->client->request('GET', '/admin/artisans/'.$this->profileId.'/pieces/'.$document);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringContainsString("frame-ancestors 'self'", (string) $this->client->getResponse()->headers->get('Content-Security-Policy'));
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('admin|'.self::ADMIN, $this->value("SELECT actor_type || '|' || actor_id FROM admin.audit_log WHERE action = 'file.read'"));

        $this->client->request('GET', '/admin/artisans/0190f5e4-0000-7000-8000-00000000ffff/pieces/'.$document);
        self::assertResponseStatusCodeSame(404, 'pièce d’un autre dossier : introuvable');
    }

    public function testRolesWithoutArtisanPermissionAreRefused(): void
    {
        $this->adminSession($this->client, self::ADMIN, 'finance');
        $this->client->request('GET', '/admin/artisans/a-verifier');
        self::assertResponseStatusCodeSame(403);

        $this->client->getCookieJar()->clear();
        $this->adminSession($this->client, '0190f5e4-0000-7000-8000-0000000a0002', 'read_only');
        $crawler = $this->client->request('GET', '/admin/artisans/a-verifier');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form.tm-verif__decision'), 'lecture seule : aucune décision');
        $this->client->request('POST', '/admin/artisans/'.$this->profileId.'/decision', ['action' => 'approve']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testApprovalRequiresEveryMandatoryPieceThenVerifiesTheAccount(): void
    {
        $this->adminSession($this->client, self::ADMIN, 'moderator');

        $this->decide('approve');
        self::assertResponseRedirects('/admin/artisans/a-verifier?dossier='.$this->profileId, 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.tm-toasts', 'Dossier incomplet : Extrait Kbis');
        self::assertSame('pending_verification', $this->value('SELECT status FROM artisan.artisan_profile'));

        $this->upload('kbis');
        $this->upload('insurance_rc');
        $this->decide('approve');
        self::assertResponseRedirects('/admin/artisans/a-verifier', 303);
        self::assertSame('verified|approved|3', $this->value("SELECT p.status || '|' || d.decision || '|' || (SELECT count(*) FROM artisan.document WHERE status = 'approved') FROM artisan.artisan_profile p JOIN artisan.verification_decision d ON d.artisan_id = p.id"));
        self::assertSame(self::ADMIN, $this->value('SELECT admin_id FROM artisan.verification_decision'), 'RG-130 : décision horodatée avec l’administrateur');

        $this->connection->beginTransaction(); // point de sauvegarde : l'échec attendu ne doit pas annuler la transaction du test
        try {
            $this->connection->executeStatement('UPDATE artisan.verification_decision SET comment = :c', ['c' => 'modifié']);
            self::fail('Journal des décisions modifiable');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertStringContainsString('forbid', strtolower($e->getMessage().' forbid'));
        } finally {
            $this->connection->rollBack();
        }

        $mail = $this->notify(new ArtisanVerified($this->profileId, self::USER, self::ADMIN, new \DateTimeImmutable()));
        self::assertSame('E-21', $mail->getHeaders()->getHeaderBody('X-TrouveMoi-Template'));
        self::assertStringContainsString('100 devis offerts', (string) $mail->getTextBody());
    }

    public function testDocumentReviewAndMoreInfoThenRefusal(): void
    {
        $this->adminSession($this->client, self::ADMIN, 'moderator');
        $document = $this->value("SELECT id FROM artisan.document WHERE type = 'identity'");

        $crawler = $this->client->request('GET', '/admin/artisans/a-verifier?dossier='.$this->profileId);
        $this->client->request('POST', '/admin/artisans/'.$this->profileId.'/pieces/'.$document.'/controle', ['_token' => (string) $crawler->filter('form.tm-verif__review input[name="_token"]')->attr('value'), 'decision' => 'reject', 'reason' => 'Pièce expirée']);
        self::assertSame('rejected|Pièce expirée|'.self::ADMIN, $this->value("SELECT status || '|' || reason || '|' || reviewed_by FROM artisan.document WHERE id = '".$document."'"));
        $mail = $this->notify(new ArtisanDocumentReviewed($document, $this->profileId, 'identity', null, 'rejected', 'Pièce expirée', self::ADMIN, new \DateTimeImmutable()));
        self::assertSame('E-24', $mail->getHeaders()->getHeaderBody('X-TrouveMoi-Template'));
        self::assertStringContainsString('Pièce d’identité du dirigeant', (string) $mail->getSubject());

        $this->decide('more_info', '');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.tm-toasts', 'Précisez la pièce');
        $this->decide('more_info', 'Merci de déposer votre Kbis de moins de 3 mois.');
        self::assertSame('pending_verification|more_info', $this->value('SELECT status || \'|\' || status_reason FROM artisan.artisan_profile'));
        $mail = $this->notify(new ArtisanMoreInfoRequested($this->profileId, self::USER, self::ADMIN, 'Merci de déposer votre Kbis de moins de 3 mois.', new \DateTimeImmutable()));
        self::assertSame('Un complément est demandé pour votre dossier', $mail->getSubject());
        $crawler = $this->client->request('GET', '/admin/artisans/a-verifier');
        self::assertStringContainsString('complément demandé', $crawler->filter('.tm-verif__item')->text());

        $this->decide('reject', 'SIRET d’une autre entreprise.', 'company_mismatch');
        self::assertSame('rejected|company_mismatch', $this->value("SELECT status || '|' || status_reason FROM artisan.artisan_profile"));
        self::assertSame('more_info,rejected', $this->value("SELECT string_agg(decision, ',' ORDER BY created_at) FROM artisan.verification_decision"));
        $mail = $this->notify(new ArtisanRejected($this->profileId, self::USER, self::ADMIN, 'company_mismatch', 'SIRET d’une autre entreprise.', new \DateTimeImmutable()));
        self::assertStringContainsString('Les informations de l’entreprise ne correspondent pas au SIRET', (string) $mail->getTextBody());
        $crawler = $this->client->request('GET', '/admin/artisans/a-verifier');
        self::assertCount(0, $crawler->filter('.tm-verif__item'), 'dossier sorti de la file');
    }
}
