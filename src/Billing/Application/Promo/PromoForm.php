<?php

declare(strict_types=1);

namespace App\Billing\Application\Promo;

use App\Billing\Domain\Promo\PromoRuleViolation;
use App\Billing\Domain\Promo\PromoTerms;

/** Lecture du formulaire BO08.3 (« 20 », « 20,5 », « 10,00 € », date au format AAAA-MM-JJ, cases à cocher). */
final class PromoForm
{
    private const int CENTS = 100;

    /** @param array<string, mixed> $input */
    public static function terms(array $input, \DateTimeImmutable $now): PromoTerms
    {
        $text = static fn (string $k): string => \is_string($input[$k] ?? null) ? trim($input[$k]) : '';
        $type = PromoTerms::AMOUNT === $text('discount_type') ? PromoTerms::AMOUNT : PromoTerms::PERCENT;
        $value = self::cents($text('value'));
        $duration = \in_array($text('duration'), PromoTerms::DURATIONS, true) ? $text('duration') : 'once';
        $plans = \is_array($input['plans'] ?? null) ? array_values(array_filter($input['plans'], static fn (mixed $p): bool => \is_string($p) && 1 === preg_match('/^[0-9a-f-]{36}$/', $p))) : [];
        $until = null;
        if ('' !== $text('valid_until')) {
            $until = \DateTimeImmutable::createFromFormat('!Y-m-d', $text('valid_until'), new \DateTimeZone('Europe/Paris'));
            if (false === $until) {
                throw new PromoRuleViolation('Date de fin invalide.');
            }
            $until = $until->modify('+1 day');
            if ($until <= $now) {
                throw new PromoRuleViolation('La date de fin doit être dans le futur.');
            }
        }

        return new PromoTerms(
            strtoupper($text('code')),
            $type,
            PromoTerms::PERCENT === $type ? $value : null,
            PromoTerms::AMOUNT === $type ? $value : null,
            $duration,
            'repeating' === $duration ? self::int($text('duration_months')) : null,
            $plans,
            $until,
            self::int($text('max_redemptions')),
            self::int($text('max_per_account')),
            '1' === $text('new_subscribers_only'),
        );
    }

    /** « 20 » → 2000 (points de base ou centimes), « 20,5 » → 2050, vide → null. */
    private static function cents(string $value): ?int
    {
        $value = str_replace([' ', "\u{a0}", '€', '%'], '', $value);
        if ('' === $value) {
            return null;
        }
        if (1 !== preg_match('/^(\d{1,6})(?:[.,](\d{1,2}))?$/', $value, $m)) {
            throw new PromoRuleViolation('Remise invalide (ex. : 20 ou 9,90).');
        }

        return (int) $m[1] * self::CENTS + (int) str_pad($m[2] ?? '0', 2, '0');
    }

    private static function int(string $value): ?int
    {
        if ('' === $value) {
            return null;
        }
        if (1 !== preg_match('/^\d{1,6}$/', $value)) {
            throw new PromoRuleViolation('Les nombres doivent être des entiers positifs.');
        }

        return (int) $value;
    }
}
