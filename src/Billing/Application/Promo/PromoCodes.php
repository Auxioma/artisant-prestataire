<?php

declare(strict_types=1);

namespace App\Billing\Application\Promo;

use App\Billing\Domain\Promo\PromoTerms;

/** Codes promo (`billing.promo_code`) et leurs utilisations (`billing.promo_redemption`). */
interface PromoCodes
{
    public function codeExists(string $code): bool;

    public function add(string $id, PromoTerms $terms, string $stripeCouponId, string $stripePromotionCodeId, string $adminId, \DateTimeImmutable $at): void;

    public function find(string $id, \DateTimeImmutable $now): ?PromoCodeView;

    public function findByCode(string $code, \DateTimeImmutable $now): ?PromoCodeView;

    /** @return list<PromoCodeView> */
    public function search(string $query, ?string $state, \DateTimeImmutable $now): array;

    public function disable(string $id, \DateTimeImmutable $at): void;

    public function redemptionsBy(string $promoCodeId, string $artisanId): int;

    public function recordRedemption(string $promoCodeId, string $subscriptionId, string $artisanId, \DateTimeImmutable $at): void;
}
