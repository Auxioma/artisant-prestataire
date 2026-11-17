<?php

declare(strict_types=1);

namespace App\Quote\Application\Editor;

use App\Quote\Domain\Quote\Deposit;
use App\Quote\Domain\Quote\LineType;
use App\Quote\Domain\Quote\QuoteConditions;
use App\Quote\Domain\Quote\QuoteContent;
use App\Quote\Domain\Quote\QuoteLine;
use App\Quote\Domain\Quote\QuoteRuleViolation;
use App\Quote\Domain\Quote\VatRate;

/**
 * PR04 — lecture de la saisie de l'éditeur (formats français : « 1 150,00 », « 2,5 », « 5 % ») en contenu de devis.
 * Les lignes entièrement vides sont ignorées.
 */
final class QuoteForm
{
    private const int MAX_DECIMALS_MONEY = 2;
    private const int MAX_DECIMALS_QUANTITY = 3;

    /** @param array<string, mixed> $input */
    public static function content(array $input, bool $vatExempt, int $defaultValidityDays): QuoteContent
    {
        $lines = [];
        foreach (\is_array($input['lines'] ?? null) ? $input['lines'] : [] as $raw) {
            if (!\is_array($raw)) {
                continue;
            }
            $label = self::text($raw, 'label') ?? '';
            $type = LineType::tryFrom(self::text($raw, 'type') ?? '') ?? LineType::Service;
            if ('' === $label && null === self::text($raw, 'quantity') && null === self::text($raw, 'unit_price')) {
                continue;
            }
            $lines[] = new QuoteLine(
                $type,
                $label,
                self::text($raw, 'description'),
                $type->isPriced() ? self::decimal(self::text($raw, 'quantity') ?? '1', self::MAX_DECIMALS_QUANTITY, 'Quantité invalide.') : 0,
                self::text($raw, 'unit'),
                $type->isPriced() ? self::decimal(self::text($raw, 'unit_price') ?? '0', self::MAX_DECIMALS_MONEY, 'Prix unitaire invalide.') : 0,
                $type->isPriced() ? self::percent(self::text($raw, 'discount')) : 0,
                VatRate::tryFrom((int) (self::text($raw, 'vat') ?? (string) VatRate::Standard->value)) ?? VatRate::Standard,
            );
        }
        $depositValue = self::text($input, 'deposit_value');
        $deposit = match (self::text($input, 'deposit_type')) {
            'percent' => null === $depositValue ? null : Deposit::percent(self::percent($depositValue)),
            'amount' => null === $depositValue ? null : Deposit::amount(self::decimal($depositValue, self::MAX_DECIMALS_MONEY, 'Montant d’acompte invalide.')),
            default => null,
        };
        $start = self::text($input, 'start_date');
        $startDate = null === $start ? null : (\DateTimeImmutable::createFromFormat('!Y-m-d', $start) ?: throw new QuoteRuleViolation('Date de début invalide.'));
        $validity = self::text($input, 'validity_days');

        return new QuoteContent(
            self::text($input, 'subject') ?? '',
            $lines,
            new QuoteConditions(
                null === $validity ? $defaultValidityDays : (ctype_digit($validity) ? (int) $validity : throw new QuoteRuleViolation('Durée de validité invalide.')),
                self::text($input, 'execution_delay'),
                $startDate,
                self::text($input, 'payment_terms'),
                array_values(array_filter(\is_array($input['payment_methods'] ?? null) ? $input['payment_methods'] : [], static fn (mixed $m): bool => \is_string($m) && '' !== trim($m))),
                self::text($input, 'guarantees'),
                self::text($input, 'special_conditions'),
                self::text($input, 'client_note'),
            ),
            self::percent(self::text($input, 'global_discount')),
            $deposit,
            $vatExempt,
        );
    }

    /** « 1 150,50 » → 115050 (2 décimales) ; « 2,5 » → 2500 (3 décimales). */
    public static function decimal(string $value, int $decimals, string $error): int
    {
        $normalized = str_replace([' ', "\u{a0}", "\u{202f}", ','], ['', '', '', '.'], trim($value));
        if (1 !== preg_match('/^\d{1,9}(\.\d{1,'.$decimals.'})?$/', $normalized)) {
            throw new QuoteRuleViolation($error);
        }
        [$int, $frac] = array_pad(explode('.', $normalized), 2, '');

        return (int) $int * 10 ** $decimals + (int) str_pad($frac, $decimals, '0');
    }

    /** « 5 » ou « 5,5 % » → points de base (5 % = 500). */
    private static function percent(?string $value): int
    {
        if (null === $value) {
            return 0;
        }

        return self::decimal(rtrim(str_replace('%', '', $value)), self::MAX_DECIMALS_MONEY, 'Pourcentage invalide.');
    }

    /** @param array<array-key, mixed> $input */
    private static function text(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;
        if (\is_int($value) || \is_float($value)) {
            $value = (string) $value;
        }

        return \is_string($value) && '' !== trim($value) ? trim($value) : null;
    }
}
