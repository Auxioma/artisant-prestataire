<?php

declare(strict_types=1);

namespace App\Tests\Functional\ArtisanProfile;

use App\ArtisanProfile\Application\Maintenance\HideUninsuredProfiles;
use App\ArtisanProfile\Application\Maintenance\RestoreVisibility;
use App\ArtisanProfile\Application\Maintenance\SendDocumentExpiryAlerts;
use App\ArtisanProfile\Application\Maintenance\SendSignupReminders;
use App\ArtisanProfile\Application\Signup\DeclareInsurances;
use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\ArtisanProfile\Application\Verification\ReviewDocument;
use App\ArtisanProfile\Domain\Document\Event\ArtisanDocumentReviewed;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Email;

/** T-212 : alertes d'échéance J-30 / J-7 / J0 (RG-032, E-25), masquage RG-130, relances E-23 — idempotentes. */
final class ArtisanMaintenanceTest extends KernelTestCase
{
    use ArtisanDossier;

    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a0001';

    private Connection $connection;
    private string $profileId;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $this->connection = $connection;
        $this->prepareReferentials();
        $this->profileId = $this->submittedDossier(rcDays: 20);
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    /** @return list<Email> */
    private function mails(): array
    {
        return array_values(array_filter(self::getMailerMessages(), static fn ($m): bool => $m instanceof Email));
    }

    private function verified(): void
    {
        $this->uploadPiece('kbis');
        $this->uploadPiece('insurance_rc');
        $this->dossierDispatch(new ApproveArtisan($this->profileId, self::ADMIN));
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    public function testExpiryAlertsAreSentOncePerStepAndExpiredPiecesAreFlagged(): void
    {
        $this->verified();
        $rc = $this->dossierValue("SELECT id FROM artisan.document WHERE type = 'insurance_rc'");

        $this->dossierDispatch(new SendDocumentExpiryAlerts());
        $this->dossierDispatch(new SendDocumentExpiryAlerts());
        $alerts = array_filter($this->mails(), static fn (Email $m): bool => 'E-25' === $m->getHeaders()->getHeaderBody('X-TrouveMoi-Template'));
        self::assertCount(1, $alerts, 'J-30 envoyé une seule fois (RC Pro à J-20)');
        self::assertStringContainsString('expire dans 20 jour(s)', (string) array_values($alerts)[0]->getSubject());
        self::assertSame('30', $this->dossierValue("SELECT step FROM artisan.reminder_sent WHERE subject_id = '".$rc."'"));

        // Échéance dépassée : pièce « expirée », alerte finale.
        $this->connection->executeStatement('UPDATE artisan.document SET expires_on = current_date - 1 WHERE id = :id', ['id' => $rc]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->dossierDispatch(new SendDocumentExpiryAlerts());
        self::assertSame('expired', $this->dossierValue("SELECT status FROM artisan.document WHERE id = '".$rc."'"));
        self::assertSame('30,expired', $this->dossierValue("SELECT string_agg(step, ',' ORDER BY step) FROM artisan.reminder_sent WHERE subject_id = '".$rc."'"));
    }

    public function testUninsuredProfileIsHiddenAfterGraceThenRestored(): void
    {
        $this->verified();
        $this->connection->executeStatement("UPDATE artisan.artisan_profile SET insurances = jsonb_set(insurances, '{0,valid_until}', to_jsonb((current_date - 10)::text))");
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->dossierDispatch(new HideUninsuredProfiles());
        self::assertSame('', $this->dossierValue("SELECT coalesce(hidden_reason, '') FROM artisan.artisan_profile"), 'délai de grâce de 15 jours non écoulé');

        $this->connection->executeStatement("UPDATE artisan.artisan_profile SET insurances = jsonb_set(insurances, '{0,valid_until}', to_jsonb((current_date - 16)::text))");
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->dossierDispatch(new HideUninsuredProfiles());
        self::assertStringStartsWith('Assurance obligatoire expirée depuis le', $this->dossierValue('SELECT hidden_reason FROM artisan.artisan_profile'));
        self::assertSame('verified', $this->dossierValue('SELECT status FROM artisan.artisan_profile'), 'masqué, pas suspendu');
        self::assertSame(1, (int) $this->dossierValue("SELECT count(*) FROM admin.audit_log WHERE action = 'artisan.hidden'"));

        // Renouvellement : nouvelle déclaration (compte vérifié), nouvelle attestation validée → visible de nouveau.
        $this->dossierDispatch(new DeclareInsurances(self::DOSSIER_USER, [['kind' => 'rc_pro', 'insurer' => 'MAAF', 'policy' => 'RC-2', 'valid_until' => new \DateTimeImmutable('+1 year')->format('Y-m-d')]], []));
        $document = $this->uploadPiece('insurance_rc');
        $this->dossierDispatch(new ReviewDocument($this->profileId, $document, self::ADMIN, 'approve'));
        $restore = self::getContainer()->get(RestoreVisibility::class);
        \assert($restore instanceof RestoreVisibility);
        $restore(new ArtisanDocumentReviewed($document, $this->profileId, 'insurance_rc', null, 'approved', null, self::ADMIN, new \DateTimeImmutable()));
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        self::assertSame('', $this->dossierValue("SELECT coalesce(hidden_reason, '') FROM artisan.artisan_profile"));
    }

    public function testIncompleteDossiersAreRemindedAtTwoAndSevenDays(): void
    {
        // Dossier envoyé il y a 3 jours sans Kbis ni RC Pro : relance « pièces manquantes » J+2.
        $this->connection->executeStatement("UPDATE artisan.artisan_profile SET submitted_at = date_trunc('second', now()) - interval '3 days', created_at = date_trunc('second', now()) - interval '4 days'");
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->dossierDispatch(new SendSignupReminders());
        $this->dossierDispatch(new SendSignupReminders());
        $reminders = array_values(array_filter($this->mails(), static fn (Email $m): bool => 'E-23' === $m->getHeaders()->getHeaderBody('X-TrouveMoi-Template')));
        self::assertCount(1, $reminders);
        self::assertSame('Il manque des pièces à votre dossier TrouveMoi', $reminders[0]->getSubject());
        self::assertStringContainsString('Extrait Kbis', (string) $reminders[0]->getTextBody());

        $this->connection->executeStatement("UPDATE artisan.artisan_profile SET submitted_at = date_trunc('second', now()) - interval '8 days'");
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->dossierDispatch(new SendSignupReminders());
        self::assertSame('missing:2,missing:7', $this->dossierValue("SELECT string_agg(step, ',' ORDER BY step) FROM artisan.reminder_sent WHERE kind = 'signup_incomplete'"));

        // Dossier complet : plus de relance.
        $this->uploadPiece('kbis');
        $this->uploadPiece('insurance_rc');
        $this->connection->executeStatement('DELETE FROM artisan.reminder_sent');
        $this->dossierDispatch(new SendSignupReminders());
        self::assertSame('0', $this->dossierValue('SELECT count(*) FROM artisan.reminder_sent'));
    }
}
