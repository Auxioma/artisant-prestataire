<?php

declare(strict_types=1);

namespace App\Tests\Functional\Administration;

use App\Administration\Application\Settings\SettingRubric;
use App\Administration\Infrastructure\Settings\DbalSettingsProvider;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Tests\Support\AdminSession;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Contracts\Cache\CacheInterface;

/** T-804 — BO13 : rubriques, enregistrement journalisé avant / après, valeur invalide refusée, accès super-admin seul. */
final class SettingsAdminTest extends WebTestCase
{
    use AdminSession;

    private const string SUPER = '0190f5e4-0000-7000-8000-0000000a9131';
    private const string MODERATOR = '0190f5e4-0000-7000-8000-0000000a9132';

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $this->connection = $connection;
    }

    protected function tearDown(): void
    {
        $cache = self::getContainer()->get('cache.settings');
        \assert($cache instanceof CacheInterface);
        $cache->delete(DbalSettingsProvider::CACHE_KEY);
        parent::tearDown();
    }

    public function testEverySettingOutsideSeoBelongsToARubric(): void
    {
        foreach (SettingKey::cases() as $key) {
            self::assertTrue(str_starts_with($key->value, 'seo.') || null !== SettingRubric::of($key), $key->value);
        }
    }

    public function testSuperAdminEditsARubricAndSeesTheJournal(): void
    {
        $this->adminSession($this->client, self::SUPER, 'super_admin');
        $page = $this->client->request('GET', '/admin/parametres');
        self::assertResponseIsSuccessful();
        self::assertSame('Demandes et devis', $page->filter('#bo13-title')->text());
        self::assertStringContainsString('quotes.max_per_request', $page->filter('#bo13-form')->text());
        self::assertStringContainsString('Paiement (Stripe)', $page->filter('#bo13-stripe')->text());

        $form = $page->filter('#bo13-form')->form();
        $form['v[quotes.max_per_request]'] = '4';
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/parametres');
        $page = $this->client->followRedirect();
        self::assertStringContainsString('1 paramètre(s) enregistré(s)', $page->filter('body')->text());
        self::assertSame(4, $this->settings()->int(SettingKey::QuotesMaxPerRequest));
        $journal = $page->filter('.tm-bo13-log')->text();
        self::assertStringContainsString('quotes.max_per_request', $journal);
        self::assertStringContainsString('→ 4', $journal);
        self::assertSame('BO13 : Demandes et devis', $this->connection->fetchOne("SELECT reason FROM admin.setting_history WHERE key = 'quotes.max_per_request' ORDER BY changed_at DESC LIMIT 1"));

        // Hors bornes : rien n'est enregistré, l'erreur est affichée.
        $form = $page->filter('#bo13-form')->form();
        $form['v[quotes.max_per_request]'] = '9999';
        $this->client->submit($form);
        $page = $this->client->followRedirect();
        self::assertStringContainsString('quotes.max_per_request', $page->filter('.tm-toasts')->text());
        self::assertSame(4, $this->settings()->int(SettingKey::QuotesMaxPerRequest));

        // Rubrique sans réglage et rubrique inconnue.
        $page = $this->client->request('GET', '/admin/parametres/cartographie');
        self::assertCount(0, $page->filter('#bo13-form'));
        $this->client->request('GET', '/admin/parametres/inconnue');
        self::assertResponseStatusCodeSame(404);
    }

    public function testOtherRolesCannotOpenTheSettings(): void
    {
        $this->adminSession($this->client, self::MODERATOR, 'moderator');
        $this->client->request('GET', '/admin/parametres');
        self::assertResponseStatusCodeSame(403);
    }

    private function settings(): SettingsProvider
    {
        $cache = self::getContainer()->get('cache.settings');
        \assert($cache instanceof CacheInterface);
        $cache->delete(DbalSettingsProvider::CACHE_KEY);
        $settings = self::getContainer()->get(SettingsProvider::class);
        \assert($settings instanceof DbalSettingsProvider);
        $settings->reset();

        return $settings;
    }
}
