<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Shared\Infrastructure\DependencyInjection\ExternalHttpClientsPass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Anti-SSRF (cadrage §5.3) : aucun client sortant ne peut atteindre le réseau local, privé ou de métadonnées. */
final class ExternalHttpClientsTest extends KernelTestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function targets(): iterable
    {
        foreach (ExternalHttpClientsPass::EXTERNAL_CLIENTS as $client) {
            yield $client.' → boucle locale' => [$client, 'http://127.0.0.1:7700/indexes'];
            yield $client.' → métadonnées cloud' => [$client, 'http://169.254.169.254/latest/meta-data/'];
            yield $client.' → réseau privé' => [$client, 'http://10.0.0.1/'];
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function officialHosts(): iterable
    {
        yield 'geo_api.client' => ['geo_api.client', 'geo.api.gouv.fr'];
        yield 'geocoding.client' => ['geocoding.client', 'data.geopf.fr'];
        yield 'sirene.client' => ['sirene.client', 'api.insee.fr'];
        yield 'vies.client' => ['vies.client', 'ec.europa.eu'];
        yield 'turnstile.client' => ['turnstile.client', 'challenges.cloudflare.com'];
        yield 'hibp.client' => ['hibp.client', 'api.pwnedpasswords.com'];
        yield 'stripe.client' => ['stripe.client', 'api.stripe.com'];
    }

    /**
     * Chemin relatif (usage réel des adaptateurs) résolu contre base_uri, puis bloqué parce que le DNS de l'hôte
     * officiel est forcé vers la boucle locale (simulation de rebond DNS). Aucun appel réseau sortant.
     */
    #[DataProvider('officialHosts')]
    public function testRelativePathsResolveAgainstBaseUriAndDnsRebindingIsBlocked(string $clientId, string $host): void
    {
        self::bootKernel();
        $client = self::getContainer()->get('test.'.$clientId);
        self::assertInstanceOf(HttpClientInterface::class, $client);

        try {
            $client->request('GET', 'chemin-relatif', ['resolve' => [$host => '127.0.0.1']])->getStatusCode();
            self::fail('Requête vers la boucle locale non bloquée');
        } catch (TransportException $e) {
            self::assertMatchesRegularExpression('/blocked/i', $e->getMessage());
        }
        self::assertCount(\count(ExternalHttpClientsPass::EXTERNAL_CLIENTS), iterator_to_array(self::officialHosts()), 'chaque client sortant a son hôte officiel');
    }

    #[DataProvider('targets')]
    public function testPrivateNetworksAreBlocked(string $clientId, string $url): void
    {
        self::bootKernel();
        $client = self::getContainer()->get('test.'.$clientId); // alias publics déclarés en environnement de test
        self::assertInstanceOf(HttpClientInterface::class, $client);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessageMatches('/blocked/i');
        $client->request('GET', $url)->getStatusCode();
    }
}
