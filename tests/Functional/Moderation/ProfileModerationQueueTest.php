<?php

declare(strict_types=1);

namespace App\Tests\Functional\Moderation;

use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\AdminSession;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/** BO05 (Figma 186:2172) : relecture RG-031 des contenus de profils (valider, retirer avec motif), règles automatiques éditables. */
final class ProfileModerationQueueTest extends WebTestCase
{
    use AdminSession;
    use ArtisanDossier;

    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a7001';
    private const string REVIEW_SERVICES = '0190f5e4-0000-7000-8000-0000000e7001';
    private const string REVIEW_PRESENTATION = '0190f5e4-0000-7000-8000-0000000e7002';

    private KernelBrowser $client;
    private Connection $connection;
    private string $profileId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $this->connection = $connection;
        $this->prepareReferentials();
        $this->profileId = $this->submittedDossier();
        $this->uploadPiece('kbis');
        $this->uploadPiece('insurance_rc');
        $this->dossierDispatch(new ApproveArtisan($this->profileId, self::ADMIN));
        $connection->executeStatement("UPDATE artisan.artisan_profile SET services = '[{\"title\": \"Remplacement de chauffe-eau\", \"price\": \"450 €\"}]' WHERE id = :id", ['id' => $this->profileId]);
        foreach ([[self::REVIEW_SERVICES, 'services', '{"services": [{"title": "Remplacement de chauffe-eau", "price": "450 €"}]}', '3 days'], [self::REVIEW_PRESENTATION, 'presentation', '{"slogan": "Dépannage rapide (fictif)", "description": "Plombier depuis 2009."}', '1 hour']] as [$id, $section, $content, $age]) {
            $connection->executeStatement('INSERT INTO artisan.profile_review (id, artisan_id, section, content, created_at) VALUES (:id, :a, :s, CAST(:c AS jsonb), now() - CAST(:age AS interval))', ['id' => $id, 'a' => $this->profileId, 's' => $section, 'c' => $content, 'age' => $age]);
        }
        $this->adminSession($this->client, self::ADMIN, 'moderator');
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    public function testContentIsValidatedOrWithdrawn(): void
    {
        $crawler = $this->client->request('GET', '/admin/moderation/contenus');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Contenus de profils (2)', $crawler->filter('.tm-admin-tabs')->text());
        self::assertSame(['Atelier Fictif → Prestations', 'Atelier Fictif → Présentation'], $crawler->filter('.tm-moderation__item strong')->each(static fn (Crawler $n): string => $n->text()), 'plus anciens d’abord');
        self::assertStringContainsString('SLA', $crawler->filter('.tm-moderation__item')->first()->text(), 'au-delà du délai cible de 48 h');
        self::assertStringContainsString('Remplacement de chauffe-eau — 450 €', $crawler->filter('.tm-moderation__detail')->text());
        self::assertStringContainsString('2', (string) $crawler->filter('.tm-admin__link.is-active .tm-admin__badge')->text());

        $token = (string) $crawler->filter('.tm-admin-decision input[name="_token"]')->attr('value');
        $this->client->request('POST', '/admin/moderation/contenus/'.self::REVIEW_SERVICES, ['_token' => $token, 'decision' => 'withdraw']);
        self::assertStringContainsString('Choisissez un motif', $this->client->followRedirect()->filter('.tm-toasts')->text());

        $this->client->request('POST', '/admin/moderation/contenus/'.self::REVIEW_SERVICES, ['_token' => $token, 'decision' => 'withdraw', 'reason' => 'off_topic']);
        self::assertResponseRedirects('/admin/moderation/contenus', 303);
        self::assertSame('[]', DbalValue::string($this->connection->fetchOne('SELECT services::text FROM artisan.artisan_profile WHERE id = :id', ['id' => $this->profileId])), 'prestations retirées du profil public');
        self::assertSame(['rejected', 'off_topic'], array_values((array) $this->connection->fetchAssociative('SELECT status, reason FROM artisan.profile_review WHERE id = :id', ['id' => self::REVIEW_SERVICES])));
        self::assertSame(1, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM admin.audit_log WHERE action = 'moderation.profile_content_withdrawn'")));

        $this->client->request('POST', '/admin/moderation/contenus/'.self::REVIEW_PRESENTATION, ['_token' => $token, 'decision' => 'approve']);
        self::assertSame('approved', DbalValue::string($this->connection->fetchOne('SELECT status FROM artisan.profile_review WHERE id = :id', ['id' => self::REVIEW_PRESENTATION])));
        $crawler = $this->client->request('GET', '/admin/moderation/contenus');
        self::assertStringContainsString('Aucun contenu à relire', $crawler->filter('.tm-moderation__queue')->text());

        $this->client->request('POST', '/admin/moderation/contenus/'.self::REVIEW_PRESENTATION, ['_token' => $token, 'decision' => 'approve']);
        self::assertStringContainsString('déjà été traité', $this->client->followRedirect()->filter('.tm-toasts')->text());
    }

    public function testRulesAreEditable(): void
    {
        $crawler = $this->client->request('GET', '/admin/moderation/regles');
        self::assertStringContainsString('Demande ≥ 70 · Avis ≥ 60 · Message ≥ 50', $crawler->filter('.tm-moderation__rules')->text());
        $token = (string) $crawler->filter('#mots input[name="_token"]')->attr('value');
        $this->client->request('POST', '/admin/moderation/regles', ['_token' => $token, 'form' => 'terms', 'forbidden_terms' => "arnaque\n\nescroquerie\narnaque"]);
        self::assertResponseRedirects('/admin/moderation/regles', 303);
        self::assertSame('["arnaque", "escroquerie"]', DbalValue::string($this->connection->fetchOne("SELECT value::text FROM admin.setting WHERE key = 'moderation.forbidden_terms'")));

        $form = $crawler->filter('#seuils')->form();
        $form['moderation_request_risk_threshold'] = '65';
        $this->client->submit($form);
        self::assertSame('65', DbalValue::string($this->connection->fetchOne("SELECT value::text FROM admin.setting WHERE key = 'moderation.request_risk_threshold'")));

        $form['moderation_request_risk_threshold'] = '150';
        $this->client->submit($form);
        self::assertStringContainsString('100', $this->client->followRedirect()->filter('.tm-toasts')->text(), 'borne du paramètre');
    }
}
