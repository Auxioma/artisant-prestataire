<?php

declare(strict_types=1);

namespace App\Tests\Functional\QuoteRequest;

use App\Administration\Application\Command\ChangeSetting;
use App\Administration\Infrastructure\Settings\DbalSettingsProvider;
use App\Identity\Application\Command\RegisterUser;
use App\QuoteRequest\Application\EventHandler\SendRequestSubmitted;
use App\QuoteRequest\Application\Submission\SubmitQuoteRequest;
use App\QuoteRequest\Domain\Request\Event\QuoteRequestStatusChanged;
use App\QuoteRequest\Domain\Request\Event\QuoteRequestSubmitted;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\AdminSession;
use App\Tests\Support\ArtisanDossier;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Contracts\Cache\CacheInterface;

/** RG-063 / BO04.1 (Figma 186:1669) : score de risque, file de modération, validation, refus motivé (E-10b), export, droits. */
final class RequestModerationTest extends WebTestCase
{
    use AdminSession;
    use ArtisanDossier;

    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000e6001';
    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a6001';
    private const string RISKY = '0190f5e4-0000-7000-8000-0000000e6101';
    private const string CLEAN = '0190f5e4-0000-7000-8000-0000000e6102';

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $this->connection = $connection;
        $this->prepareReferentials();
        $this->dossierDispatch(new RegisterUser(self::CLIENT, 'camille.moderation@exemple.test', 'cheval agrafe batterie', 'client', 'Camille', 'Modération'));
        $this->dossierDispatch(new ChangeSetting('moderation.forbidden_terms', ['arnaque'], self::ADMIN, 'Test RG-063'));
        $cache = self::getContainer()->get('cache.settings');
        $settings = self::getContainer()->get(DbalSettingsProvider::class);
        \assert($cache instanceof CacheInterface && $settings instanceof DbalSettingsProvider);
        $cache->delete(DbalSettingsProvider::CACHE_KEY);
        $settings->reset();

        $job = $this->dossierValue("SELECT id FROM catalog.job WHERE slug = 'plombier'");
        $this->dossierDispatch(new SubmitQuoteRequest(self::RISKY, self::CLIENT, $job, '76351', '76600', '1 rue Fictive', 'repair', 'Ce n’est pas une arnaque : fuite à réparer sous l’évier (fictif).', [], null, 'week', null));
        $this->dossierDispatch(new SubmitQuoteRequest(self::CLEAN, self::CLIENT, $job, '76351', '76600', '2 rue Fictive', 'repair', 'Fuite sous l’évier de la cuisine depuis ce matin (fictif).', [], null, 'week', null, source: 'seo'));
    }

    public function testRiskyRequestWaitsForModeration(): void
    {
        $row = $this->connection->fetchAssociative('SELECT status, risk_score, risk_signals::text AS signals, expires_at FROM request.quote_request WHERE id = :id', ['id' => self::RISKY]);
        self::assertSame(['status' => 'pending_moderation', 'risk_score' => 70, 'signals' => '["forbidden_term"]', 'expires_at' => null], $row, 'RG-063 : terme interdit (70), seuil atteint');
        self::assertSame('open', DbalValue::string($this->connection->fetchOne('SELECT status FROM request.quote_request WHERE id = :id', ['id' => self::CLEAN])));

        $this->notify(new QuoteRequestSubmitted(self::RISKY, self::CLIENT, '', '76351', 'pending_moderation', 3, null, true, new \DateTimeImmutable()));
        self::assertSame(1, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM notification.delivery WHERE code = 'E-10b' AND channel = 'email'")), 'E-10b : mise en modération');
    }

    public function testModeratorValidatesAndRefuses(): void
    {
        $this->adminSession($this->client, self::ADMIN, 'moderator');
        $crawler = $this->client->request('GET', '/admin/demandes');
        self::assertResponseIsSuccessful();
        self::assertSame('1', trim($crawler->filter('.tm-admin-kpi')->first()->filter('strong')->text()), 'KPI « En modération »');
        self::assertStringContainsString('Demandes (2)', $crawler->filter('.tm-admin-tabs')->text());
        self::assertStringContainsString('1', $crawler->filter('.tm-admin__link.is-active .tm-admin__badge')->text(), 'pastille de navigation');
        self::assertStringContainsString('En modération', $crawler->filter('tbody tr')->first()->text(), 'demandes en modération en tête');
        self::assertStringContainsString('SEO', $crawler->filter('tbody')->text());

        $crawler = $this->client->request('GET', '/admin/demandes?demande='.self::RISKY);
        self::assertStringContainsString('Terme interdit', $crawler->filter('.tm-users__record')->text());
        $token = (string) $crawler->filter('.tm-admin-decision input[name="_token"]')->attr('value');

        $this->client->request('POST', '/admin/demandes/decision', ['_token' => $token, 'ids' => [self::RISKY], 'decision' => 'reject']);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Choisissez un motif de refus', $crawler->filter('.tm-toasts')->text());

        $this->client->request('POST', '/admin/demandes/decision', ['_token' => $token, 'ids' => [self::RISKY], 'decision' => 'reject', 'reason' => 'spam', 'comment' => 'Lien publicitaire']);
        self::assertResponseRedirects();
        self::assertSame(['rejected', 'spam'], array_values((array) $this->connection->fetchAssociative('SELECT status, status_reason FROM request.quote_request WHERE id = :id', ['id' => self::RISKY])));
        self::assertSame(1, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM admin.audit_log WHERE action = 'request.moderation_reject'")));
        $this->notify(new QuoteRequestStatusChanged(self::RISKY, self::CLIENT, 'pending_moderation', 'rejected', 'spam', new \DateTimeImmutable()));
        self::assertSame(1, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM notification.delivery WHERE code = 'E-10b' AND channel = 'email'")), 'E-10b : refus motivé');

        $this->connection->executeStatement("UPDATE request.quote_request SET status = 'pending_moderation', expires_at = NULL, status_reason = NULL WHERE id = :id", ['id' => self::RISKY]);
        $this->client->request('POST', '/admin/demandes/decision', ['_token' => $token, 'ids' => [self::RISKY], 'decision' => 'approve']);
        $row = $this->connection->fetchAssociative('SELECT status, expires_at IS NOT NULL AS published FROM request.quote_request WHERE id = :id', ['id' => self::RISKY]);
        self::assertSame(['status' => 'open', 'published' => true], $row, 'publiée, échéance RG-064 démarrée');
        $this->notify(new QuoteRequestStatusChanged(self::RISKY, self::CLIENT, 'pending_moderation', 'open', 'moderation_approved', new \DateTimeImmutable()));
        self::assertSame(1, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM notification.delivery WHERE code = 'E-10' AND channel = 'email'")), 'E-10 à la publication');

        $this->client->request('GET', '/admin/demandes/export');
        self::assertResponseStatusCodeSame(403, 'export réservé (admin.requests.export)');
    }

    private function notify(QuoteRequestSubmitted|QuoteRequestStatusChanged $event): void
    {
        $handler = self::getContainer()->get(SendRequestSubmitted::class);
        \assert($handler instanceof SendRequestSubmitted);
        $handler($event);
    }
}
