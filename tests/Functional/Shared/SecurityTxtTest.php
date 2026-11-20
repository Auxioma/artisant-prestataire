<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** SECURITY.md §7 (T-905a) : `security.txt` conforme à la RFC 9116. */
final class SecurityTxtTest extends WebTestCase
{
    public function testSecurityTxtFollowsRfc9116(): void
    {
        $client = self::createClient();
        $client->request('GET', '/.well-known/security.txt');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/plain; charset=utf-8');
        $body = (string) $client->getResponse()->getContent();
        self::assertMatchesRegularExpression('/^Contact: mailto:\S+@\S+$/m', $body);
        self::assertSame(1, preg_match('/^Expires: (\S+)$/m', $body, $m));
        $expires = new \DateTimeImmutable($m[1]);
        self::assertGreaterThan(new \DateTimeImmutable('+30 days'), $expires);
        self::assertLessThan(new \DateTimeImmutable('+1 year'), $expires, 'RFC 9116 : moins d’un an');
        self::assertStringContainsString('Canonical: http://localhost/.well-known/security.txt', $body);
    }
}
