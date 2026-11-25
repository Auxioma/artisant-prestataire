<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Cadrage §5.4 / SECURITY.md §4 / ASVS 14.4 : en-têtes de sécurité présents sur chaque réponse. */
final class SecurityHeadersTest extends WebTestCase
{
    public function testHealthEndpointCarriesSecurityHeaders(): void
    {
        $client = self::createClient();
        $client->request('GET', '/healthz');

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString('{"status":"ok"}', (string) $client->getResponse()->getContent());
        self::assertResponseHeaderSame('X-Frame-Options', 'DENY');
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertResponseHeaderSame('Referrer-Policy', 'strict-origin-when-cross-origin');
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

        $permissions = (string) $client->getResponse()->headers->get('Permissions-Policy');
        self::assertStringContainsString('geolocation=(self)', $permissions);
        self::assertStringContainsString('camera=()', $permissions);

        $csp = (string) $client->getResponse()->headers->get('Content-Security-Policy');
        self::assertStringContainsString("default-src 'self'", $csp);
        self::assertStringContainsString("frame-ancestors 'none'", $csp);
        self::assertStringContainsString("object-src 'none'", $csp);
        self::assertStringNotContainsString('unsafe-inline', $csp);
        self::assertStringNotContainsString('unsafe-eval', $csp);
    }

    public function testHealthEndpointRejectsMutatingMethods(): void
    {
        $client = self::createClient();
        $client->request('POST', '/healthz');

        self::assertResponseStatusCodeSame(405);
    }
}
