<?php

declare(strict_types=1);

namespace App\Tests\Functional\Search;

use App\Catalog\Application\Geo\GeoReferentialImporter;
use App\Catalog\Application\Geo\Port\GeoReferentialSourceFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/** GET /api/suggestions (P01.2) : validation, réponse JSON en texte brut, limitation de débit anti-scraping. */
final class AutocompleteEndpointTest extends WebTestCase
{
    /** @return array<mixed> */
    private static function json(KernelBrowser $client): array
    {
        $data = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    public function testReturnsPlacesForAPostalCode(): void
    {
        $client = self::createClient();
        $importer = self::getContainer()->get(GeoReferentialImporter::class);
        $sources = self::getContainer()->get(GeoReferentialSourceFactory::class);
        \assert($importer instanceof GeoReferentialImporter && $sources instanceof GeoReferentialSourceFactory);
        $importer->import($sources->file(__DIR__.'/../../fixtures/geo/normandie.json'), false);

        $client->request('GET', '/api/suggestions?type=lieu&q=76600');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        $data = self::json($client);
        self::assertSame([], $data['metiers']);
        self::assertIsArray($data['lieux']);
        self::assertSame(['nom' => 'Le Havre', 'slug' => 'le-havre-76600', 'insee' => '76351', 'codesPostaux' => ['76600', '76610', '76620'], 'departement' => '76 — Seine-Maritime'], $data['lieux'][0] ?? null);
    }

    public function testShortTermReturnsNoSuggestion(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/suggestions?q=p');

        self::assertResponseIsSuccessful();
        self::assertSame(['metiers' => [], 'lieux' => []], self::json($client));
    }

    public function testRejectsInvalidParameters(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/suggestions?type=artisan&q=plomb');
        self::assertResponseStatusCodeSame(400);

        $client->request('GET', '/api/suggestions?q='.str_repeat('a', 101));
        self::assertResponseStatusCodeSame(400);

        $client->request('POST', '/api/suggestions?q=plomb');
        self::assertResponseStatusCodeSame(405);
    }

    public function testIsRateLimitedPerClient(): void
    {
        $client = self::createClient();
        // Le pool du limiteur est en mémoire en test et vidé entre deux requêtes : on épuise le quota de ce client
        // directement, avant la requête (même noyau), puis on vérifie le refus et l'isolation par adresse IP.
        $limiter = self::getContainer()->get('limiter.autocomplete');
        \assert($limiter instanceof RateLimiterFactoryInterface);
        self::assertTrue($limiter->create('127.0.0.1')->consume(30)->isAccepted());

        $client->request('GET', '/api/suggestions?q=plomb');

        self::assertResponseStatusCodeSame(429);
        self::assertTrue($client->getResponse()->headers->has('Retry-After'));
        self::assertFalse($limiter->create('127.0.0.1')->consume()->isAccepted());
        self::assertTrue($limiter->create('203.0.113.7')->consume()->isAccepted());
    }
}
