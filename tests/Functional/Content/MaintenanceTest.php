<?php

declare(strict_types=1);

namespace App\Tests\Functional\Content;

use App\Administration\Application\Command\ChangeSetting;
use App\Administration\Infrastructure\Settings\DbalSettingsProvider;
use App\Shared\Application\Bus\CommandBus;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Contracts\Cache\CacheInterface;

/** P18 : mode maintenance — 503 + Retry-After sur le site public ; back-office et sonde de santé accessibles. */
final class MaintenanceTest extends WebTestCase
{
    public function testMaintenanceModeKeepsTheBackOfficeReachable(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $bus = self::getContainer()->get(CommandBus::class);
        \assert($bus instanceof CommandBus);
        $bus->dispatch(new ChangeSetting('site.maintenance', true, '0190f5e4-0000-7000-8000-0000000a0001', 'Test maintenance'));
        $cache = self::getContainer()->get('cache.settings');
        $settings = self::getContainer()->get(DbalSettingsProvider::class);
        \assert($cache instanceof CacheInterface && $settings instanceof DbalSettingsProvider);
        $cache->delete(DbalSettingsProvider::CACHE_KEY);
        $settings->reset();

        $client->request('GET', '/categories');
        self::assertResponseStatusCodeSame(503);
        self::assertResponseHeaderSame('Retry-After', '1800');
        self::assertSelectorTextContains('h1', 'TrouveMoi fait peau neuve');

        $client->request('GET', '/admin/connexion');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/healthz');
        self::assertResponseIsSuccessful();
    }
}
