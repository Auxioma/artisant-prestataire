<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Application\Command\RecordCookieConsent;
use App\Identity\Application\Command\RecordCookieConsentHandler;
use App\Identity\Application\Port\ConsentLog;
use App\Identity\Domain\Consent\ConsentSource;
use App\Identity\Domain\Consent\CookieCategory;
use App\Identity\Domain\Consent\CookieConsent;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Domain\Exception\InvalidValue;
use App\Shared\Infrastructure\Identifier\UuidV7Generator;
use App\Tests\Support\Fake\FixedClock;
use App\Tests\Support\InMemorySettingsProvider;
use PHPUnit\Framework\TestCase;

/** CDC §3.2 / RGPD art. 7 : consentement libre, spécifique, refus par défaut, preuve horodatée. */
final class CookieConsentTest extends TestCase
{
    private const string VISITOR = '0190f5d2-6c1e-7a3b-9c4d-0000000000aa';

    /**
     * @param array<string, mixed> $choices
     */
    private function consent(array $choices): CookieConsent
    {
        $now = new \DateTimeImmutable('2026-11-11T10:00:00Z');

        return new CookieConsent($choices, 1, ConsentSource::Custom, $now, $now->modify('+6 months'));
    }

    public function testNothingIsGrantedByDefaultExceptNecessary(): void
    {
        $consent = $this->consent([]);

        self::assertTrue($consent->isGranted(CookieCategory::Necessary));
        self::assertFalse($consent->isGranted(CookieCategory::Audience));
        self::assertFalse($consent->isGranted(CookieCategory::Marketing));
        self::assertTrue($consent->isRefusedAll());
    }

    public function testNecessaryCannotBeRefusedAndOnlyStrictTrueGrants(): void
    {
        $consent = $this->consent(['necessary' => false, 'audience' => true, 'social' => 'yes', 'marketing' => 1]);

        self::assertTrue($consent->isGranted(CookieCategory::Necessary));
        self::assertTrue($consent->isGranted(CookieCategory::Audience));
        self::assertFalse($consent->isGranted(CookieCategory::Social), 'Une valeur ambiguë ne vaut pas consentement');
        self::assertFalse($consent->isGranted(CookieCategory::Marketing));
    }

    public function testRejectsUnknownCategory(): void
    {
        $this->expectException(InvalidValue::class);
        $this->consent(['fingerprinting' => true]);
    }

    /**
     * @return array{RecordCookieConsentHandler, \ArrayObject<int, CookieConsent>}
     */
    private function handler(int $policyVersion = 1): array
    {
        /** @var \ArrayObject<int, CookieConsent> $recorded */
        $recorded = new \ArrayObject();
        $log = new class($recorded) implements ConsentLog {
            /** @param \ArrayObject<int, CookieConsent> $store */
            public function __construct(private \ArrayObject $store)
            {
            }

            public function record(string $id, string $visitorId, ?string $userId, CookieConsent $consent, ?string $ipHash): void
            {
                $this->store->append($consent);
            }
        };
        $settings = new InMemorySettingsProvider()->set(SettingKey::CookiesPolicyVersion, $policyVersion)->set(SettingKey::CookiesConsentMonths, 6);
        $handler = new RecordCookieConsentHandler($log, $settings, new UuidV7Generator(), new FixedClock(new \DateTimeImmutable('2026-11-11T10:00:00Z')));

        return [$handler, $recorded];
    }

    public function testHandlerRecordsProofValidForConfiguredDuration(): void
    {
        [$handler, $recorded] = $this->handler();
        $handler(new RecordCookieConsent(self::VISITOR, ['audience' => true], 'custom', 1));

        self::assertCount(1, $recorded);
        $consent = $recorded[0];
        self::assertNotNull($consent);
        self::assertSame('2027-05-11', $consent->expiresAt->format('Y-m-d'), 'cookies.consent_months = 6');
        self::assertSame(ConsentSource::Custom, $consent->source);
    }

    public function testHandlerRejectsObsoletePolicyVersion(): void
    {
        [$handler] = $this->handler(policyVersion: 2);

        $this->expectException(InvalidValue::class);
        $handler(new RecordCookieConsent(self::VISITOR, [], 'refuse_all', 1));
    }

    public function testHandlerRejectsUnknownSource(): void
    {
        [$handler] = $this->handler();

        $this->expectException(InvalidValue::class);
        $handler(new RecordCookieConsent(self::VISITOR, [], 'implicit_scroll', 1));
    }
}
