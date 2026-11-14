<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Promo;

use App\Billing\Application\Promo\PromoCodes;
use App\Billing\Application\Promo\PromoCodeView;
use App\Billing\Domain\Promo\PromoTerms;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

final readonly class DbalPromoCodes implements PromoCodes
{
    private const string SELECT = <<<'SQL'
        SELECT c.*, array_to_json(c.plan_ids)::text AS plan_ids_json,
               (SELECT count(*) FROM billing.promo_redemption r WHERE r.promo_code_id = c.id) AS redemptions,
               (SELECT coalesce(json_agg(p.name ORDER BY p.position), '[]')::text FROM billing.plan p WHERE p.id = ANY (c.plan_ids)) AS plan_names
        FROM billing.promo_code c
        SQL;

    public function __construct(private Connection $connection)
    {
    }

    public function codeExists(string $code): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM billing.promo_code WHERE code = :c', ['c' => $code]);
    }

    public function add(string $id, PromoTerms $terms, string $stripeCouponId, string $stripePromotionCodeId, string $adminId, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO billing.promo_code (id, code, discount_type, percent_bp, amount_cents, duration, duration_months, plan_ids, valid_until, max_redemptions, max_per_account, new_subscribers_only, stripe_coupon_id, stripe_promotion_code_id, created_by, created_at)
                VALUES (:id, :code, :type, :pct, :amount, :duration, :months, CAST(:plans AS uuid[]), :until, :max, :per, :new, :coupon, :promo, :admin, :at)
                SQL,
            [
                'id' => $id, 'code' => $terms->code, 'type' => $terms->discountType, 'pct' => $terms->percentBp, 'amount' => $terms->amountCents,
                'duration' => $terms->duration, 'months' => $terms->durationMonths, 'plans' => '{'.implode(',', $terms->planIds).'}',
                'until' => $terms->validUntil?->format(\DATE_ATOM), 'max' => $terms->maxRedemptions, 'per' => $terms->maxPerAccount,
                'new' => $terms->newSubscribersOnly ? 'true' : 'false', 'coupon' => $stripeCouponId, 'promo' => $stripePromotionCodeId,
                'admin' => $adminId, 'at' => $at->format(\DATE_ATOM),
            ],
        );
    }

    public function find(string $id, \DateTimeImmutable $now): ?PromoCodeView
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(self::SELECT.' WHERE c.id = :id', ['id' => $id]);

        return false === $row ? null : self::view($row, $now);
    }

    public function findByCode(string $code, \DateTimeImmutable $now): ?PromoCodeView
    {
        $row = $this->connection->fetchAssociative(self::SELECT.' WHERE c.code = :code', ['code' => strtoupper(trim($code))]);

        return false === $row ? null : self::view($row, $now);
    }

    public function search(string $query, ?string $state, \DateTimeImmutable $now): array
    {
        $rows = $this->connection->fetchAllAssociative(self::SELECT." WHERE c.code ILIKE :q ESCAPE '\\' ORDER BY c.created_at DESC", ['q' => '%'.addcslashes(strtoupper(trim($query)), '%_\\').'%']);
        $views = array_map(static fn (array $r): PromoCodeView => self::view($r, $now), $rows);

        return null === $state ? $views : array_values(array_filter($views, static fn (PromoCodeView $v): bool => $v->state() === $state));
    }

    public function disable(string $id, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement("UPDATE billing.promo_code SET status = 'disabled', disabled_at = :at WHERE id = :id", ['id' => $id, 'at' => $at->format(\DATE_ATOM)]);
    }

    public function redemptionsBy(string $promoCodeId, string $artisanId): int
    {
        return DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM billing.promo_redemption WHERE promo_code_id = :p AND artisan_id = :a', ['p' => $promoCodeId, 'a' => $artisanId]));
    }

    public function recordRedemption(string $promoCodeId, string $subscriptionId, string $artisanId, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(
            'INSERT INTO billing.promo_redemption (promo_code_id, subscription_id, artisan_id, redeemed_at) VALUES (:p, :s, :a, :at) ON CONFLICT DO NOTHING',
            ['p' => $promoCodeId, 's' => $subscriptionId, 'a' => $artisanId, 'at' => $at->format(\DATE_ATOM)],
        );
    }

    /** @param array<string, mixed> $row */
    private static function view(array $row, \DateTimeImmutable $now): PromoCodeView
    {
        $int = static fn (mixed $v): ?int => null === $v ? null : DbalValue::int($v);
        $plans = json_decode(DbalValue::string($row['plan_ids_json']), true);
        $names = json_decode(DbalValue::string($row['plan_names']), true);

        return new PromoCodeView(
            DbalValue::string($row['id']),
            new PromoTerms(
                DbalValue::string($row['code']),
                DbalValue::string($row['discount_type']),
                $int($row['percent_bp']),
                $int($row['amount_cents']),
                DbalValue::string($row['duration']),
                $int($row['duration_months']),
                \is_array($plans) ? array_values(array_filter($plans, \is_string(...))) : [],
                null === $row['valid_until'] ? null : new \DateTimeImmutable(DbalValue::string($row['valid_until'])),
                $int($row['max_redemptions']),
                $int($row['max_per_account']),
                true === $row['new_subscribers_only'],
            ),
            'disabled' === $row['status'],
            DbalValue::int($row['redemptions']),
            \is_array($names) ? array_values(array_filter($names, \is_string(...))) : [],
            DbalValue::string($row['stripe_promotion_code_id']),
            $now,
        );
    }
}
