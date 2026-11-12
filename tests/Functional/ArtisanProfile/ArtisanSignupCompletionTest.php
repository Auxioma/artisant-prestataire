<?php

declare(strict_types=1);

namespace App\Tests\Functional\ArtisanProfile;

use App\ArtisanProfile\Application\EventHandler\RefreshProfileCompletion;
use App\ArtisanProfile\Application\EventHandler\SendSignupReceived;
use App\ArtisanProfile\Application\EventHandler\TrackDocumentScan;
use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\ArtisanProfile\Application\Verification\ReinstateArtisan;
use App\ArtisanProfile\Application\Verification\RejectArtisan;
use App\ArtisanProfile\Application\Verification\RequestArtisanInfo;
use App\ArtisanProfile\Application\Verification\SuspendArtisan;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanServiceAreaDefined;
use App\ArtisanProfile\Domain\Profile\Event\ArtisanSignupSubmitted;
use App\Catalog\Application\Command\CreateCategory;
use App\Catalog\Application\Command\CreateJob;
use App\Catalog\Application\Geo\GeoReferentialImporter;
use App\Catalog\Application\Geo\Port\GeoReferentialSourceFactory;
use App\Identity\Application\Legal\PublishLegalDocument;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Storage\ScanQuarantinedFile;
use App\Shared\Domain\Storage\FileAvailable;
use App\Shared\Domain\Storage\FileQuarantined;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\Fake\AcceptingHumanVerification;
use App\Tests\Support\Fake\FakeCompanyRegistry;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\Email;

/** A03 étapes 4 à 7 : zone (RG-042), assurances (RG-032), justificatifs chiffrés (ADR-0008), envoi du dossier (RG-043, E-20). */
final class ArtisanSignupCompletionTest extends WebTestCase
{
    private const string EMAIL = 'camille@exemple.test';
    private const string PASSWORD = 'cheval agrafe batterie';
    private const string HARFLEUR = '76341';
    private const string FIXTURE = __DIR__.'/../../fixtures/geo/normandie.json';

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $container = self::getContainer();
        $commands = $container->get(CommandBus::class);
        $connection = $container->get(Connection::class);
        $importer = $container->get(GeoReferentialImporter::class);
        $sources = $container->get(GeoReferentialSourceFactory::class);
        \assert($commands instanceof CommandBus && $connection instanceof Connection && $importer instanceof GeoReferentialImporter && $sources instanceof GeoReferentialSourceFactory);
        $this->connection = $connection;

        foreach (['cgu' => 'CGU', 'cgv_pro' => 'CGV pro', 'privacy' => 'Confidentialité'] as $i => $title) {
            $commands->dispatch(new PublishLegalDocument('0190f5e4-0000-7000-8000-0000000d00'.(10 + array_search($i, ['cgu', 'cgv_pro', 'privacy'], true)), $i, $title, '<p>'.$title.' fictives de test</p>', false));
        }
        $commands->dispatch(new CreateCategory('Plomberie', 'wrench'));
        $commands->dispatch(new CreateJob('plomberie', 'Plombier', requiredDocuments: ['kbis', 'insurance_rc', 'rge']));
        $commands->dispatch(new CreateJob('plomberie', 'Maçon', requiredDocuments: ['kbis', 'insurance_rc'], decennialRequired: true));
        $importer->import($sources->file(self::FIXTURE), false);
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

    /**
     * @return array<mixed>
     *
     * @phpstan-impure dernière réponse reçue
     */
    private function json(): array
    {
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        \assert(\is_array($data));

        return $data;
    }

    private function token(string $path): string
    {
        return (string) $this->client->request('GET', $path)->filter('input[name="_token"]')->first()->attr('value');
    }

    /** Étapes 1 à 3 (couvertes par ArtisanSignupTest) : compte, entreprise, deux métiers dont « Maçon » (décennale). */
    private function reachStepFour(): void
    {
        $crawler = $this->client->request('GET', '/inscription/pro');
        $this->client->request('POST', '/inscription/pro', ['artisan_account' => [
            'firstName' => 'Camille', 'lastName' => 'Exemple', 'email' => self::EMAIL, 'mobile' => '06 11 22 33 44',
            'password' => ['first' => self::PASSWORD, 'second' => self::PASSWORD], 'terms' => '1',
            'legalVersions' => (string) $crawler->filter('#artisan_account_legalVersions')->attr('value'),
            '_token' => (string) $crawler->filter('#artisan_account__token')->attr('value'),
        ], 'cf-turnstile-response' => AcceptingHumanVerification::HUMAN]);
        $this->client->request('POST', '/inscription/pro/entreprise', ['_token' => $this->token('/inscription/pro/entreprise'), 'siret' => FakeCompanyRegistry::ACTIVE, 'trade_name' => 'Atelier Fictif', 'vat_number' => 'FR68900000001', 'vat_status' => 'assujetti', 'action' => 'continue']);
        $crawler = $this->client->request('GET', '/inscription/pro/metiers');
        $ids = $crawler->filter('input[name="jobs[]"]')->each(static fn ($n): string => (string) $n->attr('value'));
        $this->client->request('POST', '/inscription/pro/metiers', ['_token' => (string) $crawler->filter('input[name="_token"]')->attr('value'), 'jobs' => $ids, 'main' => $ids[0]]);
        self::assertResponseRedirects('/inscription/pro/zone', 303);
    }

    /** @param array<string, mixed> $fields */
    private function area(array $fields): void
    {
        $this->client->request('POST', '/inscription/pro/zone', $fields + ['_token' => $this->token('/inscription/pro/zone'), 'action' => 'continue']);
    }

    private function insurances(string $until = '2027-12-31'): void
    {
        $this->client->request('POST', '/inscription/pro/assurances', [
            '_token' => $this->token('/inscription/pro/assurances'),
            'insurances' => [
                'decennial' => ['insurer' => 'AXA France', 'policy' => '4521-889-22', 'valid_until' => $until],
                'rc_pro' => ['insurer' => 'MAAF', 'policy' => 'RC-778', 'valid_until' => '2027-09-30'],
            ],
            'labels' => [['name' => 'RGE QualiPAC', 'number' => 'E-12345', 'valid_until' => '2027-03-31']],
            'action' => 'continue',
        ]);
    }

    private function upload(string $slot, string $path, string $name, string $field = 'document'): void
    {
        $this->client->request('POST', '/inscription/pro/justificatifs', ['_token' => $this->token('/inscription/pro/justificatifs'), 'slot' => $slot, 'action' => 'upload'], [$field => new UploadedFile($path, $name, null, null, true)]);
    }

    private function scan(string $fileId): void
    {
        $scan = self::getContainer()->get(ScanQuarantinedFile::class);
        $track = self::getContainer()->get(TrackDocumentScan::class);
        \assert($scan instanceof ScanQuarantinedFile && $track instanceof TrackDocumentScan);
        $purpose = $this->value("SELECT purpose FROM shared.stored_file WHERE id = '".$fileId."'");
        $scan(new FileQuarantined($fileId, $purpose, new \DateTimeImmutable()));
        // En production, le bus d'événements enregistre la transaction (middleware doctrine_transaction).
        $track(new FileAvailable($fileId, $purpose, new \DateTimeImmutable()));
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->flush();
    }

    public function testFullTunnelFromZoneToSubmission(): void
    {
        $this->reachStepFour();

        // Étape 4 : commune saisie par son nom, résolue sans JavaScript ; aperçu de couverture.
        $this->area(['mode' => 'radius', 'center' => 'Harfleur', 'radius' => '30']);
        self::assertResponseRedirects('/inscription/pro/assurances', 303);
        self::assertSame('radius|76341|30|4', $this->value("SELECT area_mode || '|' || area_center_insee || '|' || area_radius_km || '|' || completed_step FROM artisan.artisan_profile"));
        $crawler = $this->client->request('GET', '/inscription/pro/zone');
        self::assertSame('Harfleur (76700)', $crawler->filter('#center')->attr('value'));
        self::assertMatchesRegularExpression('/\d+ communes? couvertes? · [\d  ]+ habitants/u', $crawler->filter('.tm-area__coverage')->text());

        // Étape 5 : décennale exigée par « Maçon », RC Pro par le référentiel.
        $crawler = $this->client->request('GET', '/inscription/pro/assurances');
        self::assertStringContainsString('obligatoire pour vos métiers', $crawler->filter('.tm-insurance legend')->first()->text());
        $this->insurances();
        self::assertResponseRedirects('/inscription/pro/justificatifs', 303);
        self::assertSame('2|1|5', $this->value("SELECT jsonb_array_length(insurances) || '|' || jsonb_array_length(qualifications) || '|' || completed_step FROM artisan.artisan_profile"));

        // Étape 6 : pièces attendues selon les métiers, les assurances et les labels déclarés.
        $crawler = $this->client->request('GET', '/inscription/pro/justificatifs');
        $labels = $crawler->filter('.tm-doc__label')->each(static fn ($n): string => $n->text());
        self::assertSame([
            'Pièce d’identité du dirigeant', 'Extrait Kbis ou avis de situation INSEE (< 3 mois)', 'Attestation d’assurance décennale',
            'Attestation d’assurance RC Professionnelle', 'Certificat RGE', 'Certificat RGE QualiPAC', 'RIB (non requis au lancement)',
        ], $labels);
        self::assertStringContainsString('Exigé pour : Plombier', $crawler->filter('.tm-doc')->eq(4)->text());

        $this->upload('identity', StorageSamples::file(StorageSamples::jpeg()), 'piece-identite.jpg', 'photo');
        self::assertResponseRedirects('/inscription/pro/justificatifs', 303);
        $this->upload('kbis', StorageSamples::file(StorageSamples::pdf()), 'kbis.pdf');
        self::assertSame('2|quarantined', $this->value("SELECT count(*) || '|' || min(status) FROM artisan.document"));
        $this->upload('insurance_decennial', StorageSamples::file(StorageSamples::pdf()), 'decennale.pdf');
        self::assertSame('2027-12-31', $this->value("SELECT expires_on::text FROM artisan.document WHERE type = 'insurance_decennial'"), 'échéance reprise de l’assurance (RG-032)');
        self::assertSame('3|0', $this->value("SELECT count(*) || '|' || count(*) FILTER (WHERE f.wrapped_dek IS NOT NULL) FROM artisan.document d JOIN shared.stored_file f ON f.id = d.file_id"), 'chiffrés après analyse seulement');

        $this->client->request('POST', '/inscription/pro/justificatifs', ['_token' => $this->token('/inscription/pro/justificatifs'), 'action' => 'continue']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.tm-form-alert', 'Il manque : Attestation d’assurance RC Professionnelle, Certificat RGE, Certificat RGE QualiPAC.');

        $this->client->request('POST', '/inscription/pro/justificatifs', ['_token' => $this->token('/inscription/pro/justificatifs'), 'action' => 'later']);
        self::assertResponseRedirects('/inscription/pro/profil', 303);

        // Étape 7 : aperçu honnête de la carte, récapitulatif, logo servi seulement après analyse.
        $crawler = $this->client->request('GET', '/inscription/pro/profil');
        self::assertSame('Atelier Fictif', $crawler->filter('.tm-card-artisan__name')->text());
        self::assertCount(0, $crawler->filter('.tm-card-artisan__rating'), 'aucune note inventée');
        self::assertCount(0, $crawler->filter('.tm-card-artisan .tm-badge--verified'), 'pas de badge « Vérifié » avant validation');
        self::assertStringContainsString('30 km autour de Harfleur', $crawler->filter('.tm-facts')->text());
        self::assertStringContainsString('3 / 6 déposés', $crawler->filter('.tm-facts')->text());

        $this->client->request('POST', '/inscription/pro/profil', ['_token' => $this->token('/inscription/pro/profil'), 'description' => 'Brouillon conservé', 'action' => 'upload_logo'], ['logo' => new UploadedFile(StorageSamples::file(StorageSamples::png()), 'logo.png', null, null, true)]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#description', 'Brouillon conservé');
        $logo = $this->value('SELECT logo_file_id FROM artisan.artisan_profile');
        $this->client->request('GET', '/inscription/pro/logo');
        self::assertResponseStatusCodeSame(404, 'logo en quarantaine jamais servi');
        $this->scan($logo);
        $this->client->request('GET', '/inscription/pro/logo');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/png');
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');

        $this->client->request('POST', '/inscription/pro/profil', ['_token' => $this->token('/inscription/pro/profil'), 'description' => 'Plombier et maçon au Havre : dépannage, chauffe-eau, petite maçonnerie.', 'action' => 'submit']);
        self::assertResponseRedirects('/inscription/pro/dossier-envoye', 303);
        self::assertSame('pending_verification|7|true', $this->value("SELECT status || '|' || completed_step || '|' || (submitted_at IS NOT NULL)::text FROM artisan.artisan_profile"));
        self::assertSame('1', $this->value("SELECT count(*) FROM shared.outbox_message WHERE message_type = 'App\\ArtisanProfile\\Domain\\Profile\\Event\\ArtisanSignupSubmitted'"));

        $crawler = $this->client->request('GET', '/inscription/pro/dossier-envoye');
        self::assertSelectorTextContains('h1', 'Votre dossier est en cours de vérification');
        self::assertStringContainsString('48 h ouvrées', $crawler->filter('.tm-submitted')->text());
        self::assertStringContainsString('100 devis offerts dès la validation', $crawler->filter('.tm-submitted')->text());
        self::assertCount(3, $crawler->filter('.tm-submitted__checklist')->eq(0)->filter('.tm-check-list__todo'), 'pièces manquantes à compléter');
        self::assertCount(1, $crawler->filter('[data-analytics-auto-event-value="pro_signup_completed"]'));
        $crawler = $this->client->request('GET', '/inscription/pro/dossier-envoye');
        self::assertCount(0, $crawler->filter('[data-analytics-auto-event-value="pro_signup_completed"]'), 'événement émis une seule fois');
        $this->client->request('GET', '/inscription/pro/reprendre');
        self::assertResponseRedirects('/inscription/pro/dossier-envoye');

        // E-20 : accusé de dépôt.
        $handler = self::getContainer()->get(SendSignupReceived::class);
        \assert($handler instanceof SendSignupReceived);
        $handler(new ArtisanSignupSubmitted($this->value('SELECT id FROM artisan.artisan_profile'), $this->value('SELECT user_id FROM artisan.artisan_profile'), new \DateTimeImmutable()));
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertSame('E-20', $mail->getHeaders()->getHeaderBody('X-TrouveMoi-Template'));
        self::assertSame(self::EMAIL, $mail->getTo()[0]->getAddress());
        self::assertStringContainsString('Atelier Fictif', (string) $mail->getTextBody());
        self::assertStringContainsString('48 h ouvrées', (string) $mail->getTextBody());
    }

    public function testZoneRulesListModeAndLiveEndpoints(): void
    {
        $this->reachStepFour();

        $this->area(['mode' => 'radius', 'center' => '']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.tm-form-alert', 'Définissez votre zone d’intervention.');

        $this->area(['mode' => 'radius', 'center' => 'Commune inexistante']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('4', $this->value('SELECT completed_step + 1 FROM artisan.artisan_profile'), 'rien d’enregistré');

        // Mode liste : département entier + commune ajoutée par son code postal.
        $this->area(['mode' => 'list', 'departments' => ['14'], 'commune_search' => '76700', 'action' => 'add_commune']);
        self::assertSelectorTextContains('.tm-chips', 'Harfleur (76700)');
        $this->area(['mode' => 'list', 'departments' => ['14'], 'communes' => [self::HARFLEUR]]);
        self::assertResponseRedirects('/inscription/pro/assurances', 303);
        self::assertSame('list|["14"]|["76341"]', $this->value("SELECT area_mode || '|' || area_departments::text || '|' || area_communes::text FROM artisan.artisan_profile"));

        $this->area(['mode' => 'list', 'departments' => ['99']]);
        self::assertSelectorTextContains('.tm-form-alert', 'département');

        $this->client->request('GET', '/inscription/pro/zone/communes?q=harf');
        self::assertSame([['insee' => self::HARFLEUR, 'label' => 'Harfleur (76700)']], $this->json());
        $this->client->request('GET', '/inscription/pro/zone/apercu?mode=radius&center='.self::HARFLEUR.'&radius=5');
        $near = $this->json();
        $this->client->request('GET', '/inscription/pro/zone/apercu?mode=radius&center='.self::HARFLEUR.'&radius=150');
        $far = $this->json();
        self::assertGreaterThanOrEqual(1, $near['communes']);
        self::assertGreaterThan($near['communes'], $far['communes'], '150 km atteignent Caen et Rouen');
        $this->client->request('GET', '/inscription/pro/zone/apercu?mode=list&departments[]=14&communes[]='.self::HARFLEUR);
        $expected = $this->connection->fetchAssociative("SELECT count(*)::int AS communes, COALESCE(sum(population), 0)::int AS population FROM catalog.commune WHERE active AND (department_code = '14' OR insee = '76341')");
        self::assertSame($expected, $this->json());
    }

    public function testExpiredInsuranceIsRefusedOnTheField(): void
    {
        $this->reachStepFour();
        $this->area(['mode' => 'radius', 'center' => 'Harfleur', 'radius' => '30']);

        $this->insurances('2020-01-31');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#decennial_valid_until_error', 'Votre attestation d’assurance semble expirée.');
        self::assertSame('4', $this->value('SELECT completed_step FROM artisan.artisan_profile'));
    }

    public function testRejectedFileReplacementAndAntivirusStatus(): void
    {
        $this->reachStepFour();
        $this->area(['mode' => 'radius', 'center' => 'Harfleur', 'radius' => '30']);
        $this->insurances();

        $this->upload('kbis', StorageSamples::file('Texte brut, ni PDF ni image'), 'kbis.txt');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.tm-form-alert', 'Fichier non accepté (PDF, JPG, PNG — 10 Mo max).');
        $this->upload('inconnu', StorageSamples::file(StorageSamples::pdf()), 'kbis.pdf');
        self::assertSelectorTextContains('.tm-form-alert', 'Choisissez le type de justificatif.');
        self::assertSame('0', $this->value('SELECT count(*) FROM artisan.document'));

        $this->upload('kbis', StorageSamples::file(StorageSamples::pdf()), 'kbis-ancien.pdf');
        $first = $this->value('SELECT file_id FROM artisan.document');
        $this->upload('kbis', StorageSamples::file(StorageSamples::pdf('BT (Kbis remplacant) Tj ET')), 'kbis-recent.pdf');
        self::assertSame('shredded|replaced|shredded', $this->value("SELECT d.status || '|' || d.reason || '|' || f.status FROM artisan.document d JOIN shared.stored_file f ON f.id = d.file_id WHERE d.file_id = '".$first."'"));

        $current = $this->value("SELECT file_id FROM artisan.document WHERE status <> 'shredded'");
        $this->scan($current);
        self::assertSame('pending_review|true', $this->value("SELECT d.status || '|' || (f.wrapped_dek IS NOT NULL)::text FROM artisan.document d JOIN shared.stored_file f ON f.id = d.file_id WHERE d.file_id = '".$current."'"));
        $crawler = $this->client->request('GET', '/inscription/pro/justificatifs');
        self::assertStringContainsString('kbis-recent.pdf', $crawler->filter('.tm-doc')->eq(1)->text());
        self::assertStringContainsString('Ajouté', $crawler->filter('.tm-doc')->eq(1)->text());
    }

    public function testStepsCannotBeSkipped(): void
    {
        $this->reachStepFour();

        $this->client->request('GET', '/inscription/pro/profil');
        self::assertResponseRedirects('/inscription/pro/zone');
        $this->client->request('GET', '/inscription/pro/justificatifs');
        self::assertResponseRedirects('/inscription/pro/zone');
        $this->client->request('GET', '/inscription/pro/dossier-envoye');
        self::assertResponseRedirects('/inscription/pro/reprendre');
        $this->client->request('GET', '/inscription/pro/logo');
        self::assertResponseStatusCodeSame(404);
    }

    private const string ADMIN = '0190f5e4-0000-7000-8000-00000000ad01';

    private function dispatch(object $command): void
    {
        $bus = self::getContainer()->get(CommandBus::class);
        \assert($bus instanceof CommandBus && $command instanceof \App\Shared\Application\Bus\Command);
        $bus->dispatch($command);
    }

    /** Tunnel complet jusqu'à l'envoi du dossier (pièces laissées pour plus tard). */
    private function submitDossier(): string
    {
        $this->reachStepFour();
        $this->area(['mode' => 'radius', 'center' => 'Harfleur', 'radius' => '30']);
        $this->insurances();
        $this->client->request('POST', '/inscription/pro/justificatifs', ['_token' => $this->token('/inscription/pro/justificatifs'), 'action' => 'later']);
        $this->client->request('POST', '/inscription/pro/profil', ['_token' => $this->token('/inscription/pro/profil'), 'description' => 'Plombier au Havre (compte fictif de test).', 'action' => 'submit']);
        self::assertResponseRedirects('/inscription/pro/dossier-envoye', 303);

        return $this->value('SELECT id FROM artisan.artisan_profile');
    }

    public function testCoverageProjectionsFollowJobsAndZone(): void
    {
        $this->reachStepFour();
        self::assertSame('2|1', $this->value("SELECT count(*) || '|' || count(*) FILTER (WHERE is_main) FROM artisan.artisan_job"));

        $this->area(['mode' => 'radius', 'center' => 'Harfleur', 'radius' => '150']);
        $expected = $this->value("SELECT count(*) FROM catalog.commune o JOIN catalog.commune c ON c.active AND ST_DWithin(c.centroid, o.centroid, 150000) WHERE o.insee = '76341'");
        self::assertSame($expected, $this->value('SELECT count(*) FROM artisan.service_area_commune'), 'RG-042 : communes couvertes enregistrées avec la zone');

        $this->area(['mode' => 'list', 'departments' => ['14'], 'communes' => [self::HARFLEUR], '_token' => $this->token('/inscription/pro/zone')]);
        self::assertSame(
            $this->value("SELECT count(*) FROM catalog.commune WHERE active AND (department_code = '14' OR insee = '76341')"),
            $this->value('SELECT count(*) FROM artisan.service_area_commune'),
            'zone remplacée, pas cumulée',
        );

        // Complétude recalculée à chaque changement (zone seule : poids « zone » = 15 %).
        $handler = self::getContainer()->get(RefreshProfileCompletion::class);
        \assert($handler instanceof RefreshProfileCompletion);
        $handler(new ArtisanServiceAreaDefined($this->value('SELECT id FROM artisan.artisan_profile'), new \DateTimeImmutable()));
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->flush();
        self::assertSame('15', $this->value('SELECT completion_rate FROM artisan.artisan_profile'));
    }

    public function testStatusLifecycleIsShownAndAudited(): void
    {
        $id = $this->submitDossier();
        $crawler = $this->client->request('GET', '/inscription/pro/dossier-envoye');
        self::assertStringContainsString('Complétez votre profil (40 %)', $crawler->filter('.tm-submitted')->text(), 'description 15 + zone 15 + label 10 (poids par défaut)');
        self::assertSame('40', $crawler->filter('progress.tm-completion')->attr('value'));

        $this->dispatch(new RequestArtisanInfo($id, self::ADMIN, 'Merci de déposer votre attestation RC Pro.'));
        $crawler = $this->client->request('GET', '/inscription/pro/dossier-envoye');
        self::assertSelectorTextContains('h1', 'Un complément est demandé');
        self::assertStringContainsString('Merci de déposer votre attestation RC Pro.', $crawler->filter('.tm-submitted__notice')->text());

        // BO03 : validation possible seulement avec toutes les pièces obligatoires déposées et analysées.
        foreach (['identity', 'kbis', 'insurance_decennial', 'insurance_rc', 'rge', 'qualification:RGE QualiPAC'] as $slot) {
            $this->upload($slot, StorageSamples::file(StorageSamples::pdf()), 'piece.pdf');
            $this->scan($this->value("SELECT file_id FROM artisan.document WHERE status = 'quarantined'"));
        }
        $this->dispatch(new ApproveArtisan($id, self::ADMIN));
        $this->client->request('GET', '/inscription/pro/dossier-envoye');
        self::assertSelectorTextContains('h1', 'Votre compte professionnel est vérifié');
        $this->client->request('GET', '/inscription/pro/profil');
        self::assertResponseRedirects('/inscription/pro/dossier-envoye', null, 'tunnel fermé après validation');
        $this->client->request('GET', '/inscription/pro/zone');
        self::assertResponseIsSuccessful('RG-042 : zone modifiable à tout moment');
        $this->area(['mode' => 'radius', 'center' => 'Harfleur', 'radius' => '10']);
        self::assertResponseRedirects('/inscription/pro/dossier-envoye', 303);
        self::assertSame('10', $this->value('SELECT area_radius_km FROM artisan.artisan_profile'));

        $this->dispatch(new SuspendArtisan($id, self::ADMIN, 'Signalements à examiner'));
        $crawler = $this->client->request('GET', '/inscription/pro/dossier-envoye');
        self::assertSelectorTextContains('h1', 'suspendu');
        self::assertStringContainsString('Signalements à examiner', $crawler->filter('.tm-form-alert')->text());
        $this->client->request('GET', '/inscription/pro/zone');
        self::assertResponseRedirects('/inscription/pro/dossier-envoye');

        $this->dispatch(new ReinstateArtisan($id, null));
        self::assertSame('verified', $this->value('SELECT status FROM artisan.artisan_profile'));
        self::assertSame(
            'artisan.more_info_requested,artisan.verified,artisan.suspended,artisan.reinstated',
            $this->value("SELECT string_agg(action, ',' ORDER BY seq) FROM admin.audit_log WHERE subject_type = 'artisan_profile' AND action <> 'artisan.signup_submitted'"),
        );
        self::assertSame('admin|system', $this->value("SELECT string_agg(actor_type, '|' ORDER BY seq) FROM admin.audit_log WHERE action IN ('artisan.verified', 'artisan.reinstated')"));
    }

    public function testRejectionShowsTheNormalizedReason(): void
    {
        $id = $this->submitDossier();

        $this->dispatch(new RejectArtisan($id, self::ADMIN, 'invalid_documents', 'Kbis de plus de 3 mois'));

        $crawler = $this->client->request('GET', '/inscription/pro/dossier-envoye');
        self::assertSelectorTextContains('h1', 'n’a pas été retenu');
        self::assertStringContainsString('Justificatifs illisibles, incomplets ou non valides — Kbis de plus de 3 mois', $crawler->filter('.tm-form-alert')->text());
        self::assertCount(0, $crawler->filter('.tm-completion'), 'pas de jauge sur un dossier refusé');
        $this->client->request('GET', '/inscription/pro/justificatifs');
        self::assertResponseRedirects('/inscription/pro/dossier-envoye');
    }
}
