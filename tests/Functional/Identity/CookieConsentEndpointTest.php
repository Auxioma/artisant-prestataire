<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** POST /consentement-cookies : preuve du consentement (CDC §3.2), protégée contre les requêtes intersites. */
final class CookieConsentEndpointTest extends WebTestCase
{
    private const string ORIGIN = 'http://localhost';

    /**
     * @param array<string, mixed>|string $body
     * @param array<string, string>       $headers
     */
    private function post(KernelBrowser $client, array|string $body, array $headers = ['HTTP_ORIGIN' => self::ORIGIN, 'CONTENT_TYPE' => 'application/json']): void
    {
        $client->request('POST', '/consentement-cookies', server: $headers, content: \is_string($body) ? $body : json_encode($body, \JSON_THROW_ON_ERROR));
    }

    private function proofCount(): int
    {
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);

        return DbalValue::int($connection->fetchOne('SELECT count(*) FROM identity.consent'));
    }

    public function testRecordsProofAndSetsHttpOnlyVisitorCookie(): void
    {
        $client = self::createClient();
        $this->post($client, ['choices' => ['audience' => true, 'marketing' => false], 'source' => 'custom', 'version' => 1]);

        self::assertResponseStatusCodeSame(204);
        $cookie = $client->getResponse()->headers->getCookies()[0] ?? null;
        self::assertNotNull($cookie);
        self::assertSame('tm_cid', $cookie->getName());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('lax', $cookie->getSameSite());
        self::assertSame(1, $this->proofCount());

        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $row = $connection->fetchAssociative('SELECT choices::text AS choices, ip_hash FROM identity.consent');
        \assert(\is_array($row));
        self::assertSame('{"social": false, "audience": true, "marketing": false, "necessary": true}', $row['choices']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', DbalValue::string($row['ip_hash']), 'IP pseudonymisée');
    }

    public function testRejectsCrossOriginRequest(): void
    {
        $client = self::createClient();
        $this->post($client, ['choices' => ['audience' => true], 'source' => 'custom', 'version' => 1], ['HTTP_ORIGIN' => 'https://evil.example', 'CONTENT_TYPE' => 'application/json']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->proofCount());
    }

    public function testRejectsRequestWithoutOriginOrSameSiteFetchMetadata(): void
    {
        $client = self::createClient();
        $this->post($client, ['choices' => [], 'source' => 'refuse_all', 'version' => 1], ['CONTENT_TYPE' => 'application/json']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testRejectsFormEncodedBody(): void
    {
        $client = self::createClient();
        $this->post($client, 'choices[audience]=1&source=custom&version=1', ['HTTP_ORIGIN' => self::ORIGIN, 'CONTENT_TYPE' => 'application/x-www-form-urlencoded']);

        self::assertResponseStatusCodeSame(415);
    }

    public function testRejectsObsoletePolicyVersionAndMalformedPayload(): void
    {
        $client = self::createClient();
        $this->post($client, ['choices' => ['audience' => true], 'source' => 'custom', 'version' => 99]);
        self::assertResponseStatusCodeSame(422);

        $this->post($client, ['choices' => 'all', 'source' => 'custom', 'version' => 1]);
        self::assertResponseStatusCodeSame(422);

        $this->post($client, '{not json');
        self::assertResponseStatusCodeSame(422);

        self::assertSame(0, $this->proofCount());
    }

    public function testGetIsNotAllowed(): void
    {
        $client = self::createClient();
        $client->request('GET', '/consentement-cookies');

        self::assertResponseStatusCodeSame(405);
    }

    public function testProofsAreAppendOnly(): void
    {
        $client = self::createClient();
        $this->post($client, ['choices' => [], 'source' => 'refuse_all', 'version' => 1]);
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);

        $this->expectExceptionMessageMatches('/ajout seul/');
        $connection->executeStatement("UPDATE identity.consent SET source = 'accept_all'");
    }
}
