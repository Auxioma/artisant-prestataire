<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application\Settings;

use App\Shared\Application\Settings\InvalidSettingValue;
use App\Shared\Application\Settings\SettingKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SettingDefinitionTest extends TestCase
{
    /** @return iterable<string, array{SettingKey, mixed}> */
    public static function invalidValues(): iterable
    {
        yield 'entier sous le minimum' => [SettingKey::QuotesMaxPerRequest, 0];
        yield 'entier au-delà du maximum' => [SettingKey::QuotesMaxPerRequest, 21];
        yield 'booléen pour un entier' => [SettingKey::QuotesMaxPerRequest, true];
        yield 'texte pour un entier' => [SettingKey::QuotesMaxPerRequest, 'trois'];
        yield 'mot de passe < 12 (audit V2.1)' => [SettingKey::AuthPasswordMinLength, 8];
        yield 'URL signée > 5 min (cadrage §5.5)' => [SettingKey::DocumentsSignedUrlTtlSec, 600];
        yield 'choix hors liste' => [SettingKey::ReviewsModerationMode, 'jamais'];
        yield 'liste avec doublon' => [SettingKey::CreditsAlertThresholds, [80, 80]];
        yield 'pourcentage > 100' => [SettingKey::CreditsPlanAlertPct, [80, 120]];
        yield 'ordre de consommation incomplet' => [SettingKey::CreditsConsumptionOrder, ['free', 'plan']];
        yield 'ordre de consommation inconnu' => [SettingKey::CreditsConsumptionOrder, ['free', 'plan', 'bonus']];
        yield 'pondérations ne sommant pas à 1' => [SettingKey::SearchWeights, ['job' => 0.5, 'distance' => 0.5, 'rating' => 0.5, 'responsiveness' => 0, 'completion' => 0, 'availability' => 0]];
        yield 'pondération manquante' => [SettingKey::SearchWeights, ['job' => 1.0]];
        yield 'booléen illisible' => [SettingKey::PlansRollover, 'peut-être'];
        yield 'URL non https' => [SettingKey::SocialFacebook, 'http://facebook.com/trouvemoi'];
        yield 'URL d\'un autre domaine' => [SettingKey::SocialFacebook, 'https://facebook.com.evil.example/trouvemoi'];
        yield 'URL javascript' => [SettingKey::SocialLinkedin, 'javascript:alert(1)'];
    }

    #[DataProvider('invalidValues')]
    public function testRejectsInvalidValue(SettingKey $key, mixed $value): void
    {
        $this->expectException(InvalidSettingValue::class);
        $key->definition()->normalize($key, $value);
    }

    public function testNormalizesFormInput(): void
    {
        self::assertSame(4, SettingKey::QuotesMaxPerRequest->definition()->normalize(SettingKey::QuotesMaxPerRequest, '4'));
        self::assertNull(SettingKey::CreditsFreeValidityDays->definition()->normalize(SettingKey::CreditsFreeValidityDays, ''));
        self::assertTrue(SettingKey::PlansRollover->definition()->normalize(SettingKey::PlansRollover, '1'));
        self::assertFalse(SettingKey::PlansRollover->definition()->normalize(SettingKey::PlansRollover, '0'));
        self::assertSame([80, 90, 100], SettingKey::CreditsAlertThresholds->definition()->normalize(SettingKey::CreditsAlertThresholds, [100, 80, 90]));
        self::assertSame('https://www.facebook.com/trouvemoi', SettingKey::SocialFacebook->definition()->normalize(SettingKey::SocialFacebook, 'https://www.facebook.com/trouvemoi'));
        self::assertNull(SettingKey::SocialFacebook->definition()->normalize(SettingKey::SocialFacebook, ''));
        self::assertSame(['plan', 'free', 'extra'], SettingKey::CreditsConsumptionOrder->definition()->normalize(SettingKey::CreditsConsumptionOrder, ['plan', 'free', 'extra']));
    }
}
