<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** T-906a : sondes de vivacité et de disponibilité, sans détail interne ni mise en cache. */
final class ReadinessTest extends WebTestCase
{
    public function testProbesAnswerWithoutInternals(): void
    {
        $client = self::createClient();
        foreach (['/healthz', '/readyz'] as $url) {
            $client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertSame(['status' => 'ok'], json_decode((string) $client->getResponse()->getContent(), true), $url);
            self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'), $url);
        }
    }
}
