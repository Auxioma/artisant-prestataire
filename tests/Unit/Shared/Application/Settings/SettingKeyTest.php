<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application\Settings;

use App\Shared\Application\Settings\SettingKey;
use App\Tests\Support\InMemorySettingsProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** RG-014 / annexe G : catalogue complet, défauts valides, valeurs de référence du CDC. */
final class SettingKeyTest extends TestCase
{
    /** @return iterable<string, array{SettingKey}> */
    public static function allKeys(): iterable
    {
        foreach (SettingKey::cases() as $key) {
            yield $key->value => [$key];
        }
    }

    #[DataProvider('allKeys')]
    public function testDefaultIsValidAgainstItsOwnDefinition(SettingKey $key): void
    {
        $definition = $key->definition();

        self::assertMatchesRegularExpression('/^[a-z]+\.[a-z0-9_]+$/', $key->value);
        self::assertNotSame('', $definition->label);
        self::assertSame($definition->normalize($key, $definition->default), $definition->normalize($key, $definition->normalize($key, $definition->default)));
    }

    /** @return iterable<string, array{SettingKey, mixed}> */
    public static function annexGReferenceValues(): iterable
    {
        yield 'credits.free_on_signup' => [SettingKey::CreditsFreeOnSignup, 100];
        yield 'credits.free_validity_days' => [SettingKey::CreditsFreeValidityDays, null];
        yield 'credits.alert_thresholds' => [SettingKey::CreditsAlertThresholds, [80, 90, 100]];
        yield 'credits.consumption_order' => [SettingKey::CreditsConsumptionOrder, ['free', 'plan', 'extra']];
        yield 'plans.rollover' => [SettingKey::PlansRollover, false];
        yield 'billing.grace_days' => [SettingKey::BillingGraceDays, 7];
        yield 'billing.cancel_after_days' => [SettingKey::BillingCancelAfterDays, 30];
        yield 'quotes.max_per_request' => [SettingKey::QuotesMaxPerRequest, 3];
        yield 'quotes.withdraw_refund' => [SettingKey::QuotesWithdrawRefund, false];
        yield 'requests.expiry_days' => [SettingKey::RequestsExpiryDays, 30];
        yield 'requests.max_open_per_client' => [SettingKey::RequestsMaxOpenPerClient, 10];
        yield 'requests.duplicate_window_hours' => [SettingKey::RequestsDuplicateWindowHours, 72];
        yield 'pro.max_jobs' => [SettingKey::ProMaxJobs, 5];
        yield 'availability.badge_days' => [SettingKey::AvailabilityBadgeDays, 7];
        yield 'reviews.moderation_mode' => [SettingKey::ReviewsModerationMode, 'a_priori'];
        yield 'search.radius_options' => [SettingKey::SearchRadiusOptions, [5, 10, 20, 30, 50, 100]];
        yield 'moderation.request_risk_threshold' => [SettingKey::ModerationRequestRiskThreshold, 70];
        yield 'auth.login_max_attempts' => [SettingKey::AuthLoginMaxAttempts, 5];
        yield 'auth.password_min_length' => [SettingKey::AuthPasswordMinLength, 12];
        yield 'gdpr.deletion_grace_days' => [SettingKey::GdprDeletionGraceDays, 14];
        yield 'cookies.consent_months' => [SettingKey::CookiesConsentMonths, 6];
    }

    #[DataProvider('annexGReferenceValues')]
    public function testDefaultsMatchSpecification(SettingKey $key, mixed $expected): void
    {
        self::assertSame($expected, $key->definition()->default);
    }

    public function testTypedAccessorsReturnDefaults(): void
    {
        $settings = new InMemorySettingsProvider();

        self::assertSame(3, $settings->int(SettingKey::QuotesMaxPerRequest));
        self::assertNull($settings->nullableInt(SettingKey::CreditsFreeValidityDays));
        self::assertTrue($settings->bool(SettingKey::MessagesMaskContactsBeforeAccept));
        self::assertSame('a_priori', $settings->choice(SettingKey::ReviewsModerationMode));
        self::assertSame(['14', '27', '50', '61', '76'], $settings->stringList(SettingKey::PlatformPilotDepartments));
        self::assertEqualsWithDelta(1.0, array_sum($settings->weights(SettingKey::SearchWeights)), 0.0001);
        self::assertNull($settings->nullableDecimal(SettingKey::RankingBayesM));
    }

    public function testTypeMismatchIsAProgrammingError(): void
    {
        $this->expectException(\LogicException::class);
        new InMemorySettingsProvider()->bool(SettingKey::QuotesMaxPerRequest);
    }
}
