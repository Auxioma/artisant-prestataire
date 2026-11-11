<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Application\Login\DeviceDescription;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** E-05 : appareil décrit grossièrement, jamais la chaîne User-Agent complète. */
final class DeviceDescriptionTest extends TestCase
{
    /** @return iterable<string, array{?string, string}> */
    public static function agents(): iterable
    {
        yield 'Chrome Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36', 'Chrome sur Windows'];
        yield 'Edge Windows' => ['Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/130.0 Safari/537.36 Edg/130.0', 'Edge sur Windows'];
        yield 'Safari iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1', 'Safari sur iPhone'];
        yield 'Firefox macOS' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14.0; rv:131.0) Gecko/20100101 Firefox/131.0', 'Firefox sur macOS'];
        yield 'Chrome Android' => ['Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/130.0 Mobile Safari/537.36', 'Chrome sur Android'];
        yield 'vide' => [null, 'Navigateur inconnu'];
    }

    #[DataProvider('agents')]
    public function testDescription(?string $userAgent, string $expected): void
    {
        self::assertSame($expected, DeviceDescription::fromUserAgent($userAgent));
    }
}
