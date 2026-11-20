<?php

declare(strict_types=1);

namespace App\Tests\Functional\Content;

use App\Administration\Application\Command\ChangeSetting;
use App\Administration\Infrastructure\Settings\DbalSettingsProvider;
use App\Identity\Application\Command\RegisterUser;
use App\Identity\Infrastructure\Security\UserProvider;
use App\Shared\Application\Bus\CommandBus;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Contracts\Cache\CacheInterface;

/** CDC §8 (T-904b, P-155) : pages publiques cachables par le CDN pour les seuls visiteurs sans session. */
final class PublicPageCacheTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
    }

    public function testAnonymousPublicPagesAreSharedCacheable(): void
    {
        foreach (['/', '/categories', '/aide', '/accessibilite', '/comment-ca-marche'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            $headers = $this->client->getResponse()->headers;
            self::assertTrue($headers->hasCacheControlDirective('public'), $url);
            self::assertSame('300', $headers->getCacheControlDirective('s-maxage'), $url);
            self::assertSame('0', $headers->getCacheControlDirective('max-age'), $url.' : le navigateur revalide');
            self::assertContains('Cookie', $this->client->getResponse()->getVary(), $url);
            self::assertSame([], $headers->getCookies(), $url);
        }
    }

    public function testFormsErrorsAndSignedInVisitorsStayPrivate(): void
    {
        foreach (['/connexion', '/contact', '/une-adresse-inconnue'] as $url) {
            $this->client->request('GET', $url);
            self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('private'), $url);
        }

        $bus = self::getContainer()->get(CommandBus::class);
        $provider = self::getContainer()->get(UserProvider::class);
        \assert($bus instanceof CommandBus && $provider instanceof UserProvider);
        $bus->dispatch(new RegisterUser('0190f5e4-0000-7000-8000-00000000c155', 'cache@exemple.test', 'cheval agrafe batterie', 'client', 'Camille', 'Exemple'));
        $this->client->loginUser($provider->loadUserByIdentifier('cache@exemple.test'));
        $this->client->request('GET', '/categories');
        self::assertResponseIsSuccessful();
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('private'), 'page personnalisée (en-tête connecté)');
        self::assertNull($this->client->getResponse()->headers->getCacheControlDirective('s-maxage'));
    }

    public function testZeroDisablesSharedCache(): void
    {
        $bus = self::getContainer()->get(CommandBus::class);
        \assert($bus instanceof CommandBus);
        $bus->dispatch(new ChangeSetting('site.public_cache_seconds', 0, '0190f5e4-0000-7000-8000-0000000a0001', 'Test cache'));
        $cache = self::getContainer()->get('cache.settings');
        $settings = self::getContainer()->get(DbalSettingsProvider::class);
        \assert($cache instanceof CacheInterface && $settings instanceof DbalSettingsProvider);
        $cache->delete(DbalSettingsProvider::CACHE_KEY);
        $settings->reset();

        $this->client->request('GET', '/categories');
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('private'));
    }
}
