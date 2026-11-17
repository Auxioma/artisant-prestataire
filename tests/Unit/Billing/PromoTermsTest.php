<?php

declare(strict_types=1);

namespace App\Tests\Unit\Billing;

use App\Billing\Application\Promo\PromoForm;
use App\Billing\Domain\Promo\PromoRuleViolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** §4.3 — conditions d'un code promo : saisie, bornes (alignées sur Stripe), libellés BO08.3. */
final class PromoTermsTest extends TestCase
{
    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-14 10:00:00 Europe/Paris');
    }

    public function testPercentRepeating(): void
    {
        $terms = PromoForm::terms(['code' => 'bienvenue', 'discount_type' => 'percent', 'value' => '20', 'duration' => 'repeating', 'duration_months' => '3', 'valid_until' => '2026-12-31', 'max_redemptions' => '500', 'new_subscribers_only' => '1'], self::now());

        self::assertSame(['BIENVENUE', 2000, null, 3, 500, true], [$terms->code, $terms->percentBp, $terms->amountCents, $terms->durationMonths, $terms->maxRedemptions, $terms->newSubscribersOnly]);
        self::assertSame(['−20 %', '3 mois'], [$terms->label(), $terms->durationLabel()]);
        self::assertSame('2027-01-01', $terms->validUntil?->format('Y-m-d'), 'valable toute la journée du 31/12');
    }

    public function testAmountForever(): void
    {
        $terms = PromoForm::terms(['code' => 'CAPEB76', 'discount_type' => 'amount', 'value' => '10,00 €', 'duration' => 'forever'], self::now());

        self::assertSame([1000, null, '−10,00 €', 'Toujours'], [$terms->amountCents, $terms->percentBp, $terms->label(), $terms->durationLabel()]);
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function invalid(): iterable
    {
        yield 'code trop court' => [['code' => 'AB', 'value' => '10'], 'Code invalide'];
        yield 'sans remise' => [['code' => 'FICTIF', 'value' => ''], 'Indiquez une remise'];
        yield 'plus de 100 %' => [['code' => 'FICTIF', 'value' => '150'], 'Indiquez une remise'];
        yield 'mois manquants' => [['code' => 'FICTIF', 'value' => '10', 'duration' => 'repeating'], 'Durée invalide'];
        yield 'trop de mois' => [['code' => 'FICTIF', 'value' => '10', 'duration' => 'repeating', 'duration_months' => '48'], 'Durée invalide'];
        yield 'date passée' => [['code' => 'FICTIF', 'value' => '10', 'valid_until' => '2026-01-01'], 'dans le futur'];
        yield 'utilisations nulles' => [['code' => 'FICTIF', 'value' => '10', 'max_redemptions' => '0'], 'positifs'];
    }

    /** @param array<string, string> $input */
    #[DataProvider('invalid')]
    public function testInvalidInputIsRefused(array $input, string $message): void
    {
        $this->expectException(PromoRuleViolation::class);
        $this->expectExceptionMessage($message);
        PromoForm::terms($input, self::now());
    }
}
