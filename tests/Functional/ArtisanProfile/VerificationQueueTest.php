<?php

declare(strict_types=1);

namespace App\Tests\Functional\ArtisanProfile;

use App\ArtisanProfile\Application\EventHandler\SendVerificationOutcome;
use App\ArtisanProfile\Domain\Document\Event\ArtisanDocumentReviewed;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanMoreInfoRequested;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanRejected;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanVerified;
use App\Catalog\Application\Geo\GeoReferentialImporter;
use App\Catalog\Application\Geo\Port\GeoReferentialSourceFactory;
use App\Shared\Domain\Event\DomainEvent;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\AdminSession;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

/** BO03.2 : file de vérification, contrôles, visionneuse journalisée, décisions RG-130, E-21 / E-22 / E-24. */
final class VerificationQueueTest extends WebTestCase
{
    use AdminSession;
    use ArtisanDossier;

    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a0001';

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
        $this->prepareReferentials();
        $this->profileId = $this->submittedDossier();
        StorageSamples::cleanup();
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    private function value(string $sql): string
    {
        $value = $this->connection->fetchOne($sql);

        return \is_int($value) || \is_bool($value) ? var_export($value, true) : DbalValue::string($value);
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

        $this->uploadPiece('kbis');
        $this->uploadPiece('insurance_rc');
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

        $mail = $this->notify(new ArtisanVerified($this->profileId, self::DOSSIER_USER, self::ADMIN, new \DateTimeImmutable()));
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
        $mail = $this->notify(new ArtisanMoreInfoRequested($this->profileId, self::DOSSIER_USER, self::ADMIN, 'Merci de déposer votre Kbis de moins de 3 mois.', new \DateTimeImmutable()));
        self::assertSame('Un complément est demandé pour votre dossier', $mail->getSubject());
        $crawler = $this->client->request('GET', '/admin/artisans/a-verifier');
        self::assertStringContainsString('complément demandé', $crawler->filter('.tm-verif__item')->text());

        $this->decide('reject', 'SIRET d’une autre entreprise.', 'company_mismatch');
        self::assertSame('rejected|company_mismatch', $this->value("SELECT status || '|' || status_reason FROM artisan.artisan_profile"));
        self::assertSame('more_info,rejected', $this->value("SELECT string_agg(decision, ',' ORDER BY created_at) FROM artisan.verification_decision"));
        $mail = $this->notify(new ArtisanRejected($this->profileId, self::DOSSIER_USER, self::ADMIN, 'company_mismatch', 'SIRET d’une autre entreprise.', new \DateTimeImmutable()));
        self::assertStringContainsString('Les informations de l’entreprise ne correspondent pas au SIRET', (string) $mail->getTextBody());
        $crawler = $this->client->request('GET', '/admin/artisans/a-verifier');
        self::assertCount(0, $crawler->filter('.tm-verif__item'), 'dossier sorti de la file');
    }
}
