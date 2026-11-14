<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Plan;

use App\Billing\Domain\Plan\BillingInterval;
use App\Billing\Domain\Plan\Plan;
use App\Billing\Domain\Plan\PlanId;
use App\Billing\Domain\Plan\PlanPrice;
use App\Billing\Domain\Plan\PlanRepository;
use App\Billing\Domain\Plan\PlanStatus;
use App\Billing\Domain\Plan\PlanTerms;
use App\Billing\Domain\Plan\PlanVisibility;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;

/** Persistance SQL des forfaits (schéma `billing`) ; un prix remplacé est désactivé, jamais supprimé. */
final readonly class DbalPlanRepository implements PlanRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function find(PlanId $id): ?Plan
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM billing.plan WHERE id = :id', ['id' => $id->value]);

        return false === $row ? null : $this->hydrate($row);
    }

    public function findBySlug(string $slug): ?Plan
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM billing.plan WHERE slug = :slug', ['slug' => $slug]);

        return false === $row ? null : $this->hydrate($row);
    }

    public function all(): array
    {
        return array_map($this->hydrate(...), $this->connection->fetchAllAssociative("SELECT * FROM billing.plan ORDER BY status = 'archived', position, name"));
    }

    public function subscribers(PlanId $id): int
    {
        return 0;
    }

    public function save(Plan $plan): void
    {
        $terms = $plan->terms();
        $row = [
            'slug' => $plan->slug(),
            'name' => trim($terms->name),
            'description' => $terms->description,
            'quotes_per_period' => $terms->quotesPerPeriod,
            'unlimited' => $terms->unlimited ? 'true' : 'false',
            'fair_use_cap' => $terms->fairUseCap,
            'features' => json_encode($terms->features, \JSON_THROW_ON_ERROR),
            'max_offers' => $terms->maxOffers,
            'featured' => $terms->featured ? 'true' : 'false',
            'advanced_stats' => $terms->advancedStats ? 'true' : 'false',
            'badge' => $terms->badge,
            'position' => $plan->position(),
            'visibility' => $terms->visibility->value,
            'access_code' => $terms->accessCode,
            'status' => $plan->status()->value,
            'ever_published' => $plan->everPublished() ? 'true' : 'false',
            'published_at' => $plan->publishedAt()?->format(\DATE_ATOM),
            'stripe_product_id' => $plan->stripeProductId(),
            'updated_at' => $plan->updatedAt()->format(\DATE_ATOM),
        ];
        $updated = (int) $this->connection->executeStatement(
            'UPDATE billing.plan SET '.implode(', ', array_map(static fn (string $c): string => $c.' = :'.$c, array_keys($row))).', version = version + 1 WHERE id = :id',
            $row + ['id' => $plan->id()->value],
        );
        if (0 === $updated) {
            $this->connection->insert('billing.plan', $row + ['id' => $plan->id()->value, 'created_at' => $plan->createdAt()->format(\DATE_ATOM)]);
        }

        $current = array_map(static fn (PlanPrice $p): string => $p->id, array_values($plan->prices()));
        $active = $this->connection->fetchFirstColumn('SELECT id FROM billing.plan_price WHERE plan_id = :p AND active', ['p' => $plan->id()->value]);
        foreach ($active as $id) {
            if (!\in_array($id, $current, true)) {
                $this->connection->executeStatement('UPDATE billing.plan_price SET active = false, deactivated_at = :at WHERE id = :id', ['at' => $plan->updatedAt()->format(\DATE_ATOM), 'id' => $id]);
            }
        }
        foreach ($plan->prices() as $price) {
            if (!\in_array($price->id, $active, true)) {
                $this->connection->insert('billing.plan_price', [
                    'id' => $price->id,
                    'plan_id' => $plan->id()->value,
                    'interval' => $price->interval->value,
                    'amount_cents' => $price->amountCents,
                    'stripe_price_id' => $price->stripePriceId,
                    'created_at' => $price->since->format(\DATE_ATOM),
                ]);
            }
        }
    }

    public function delete(PlanId $id): void
    {
        $this->connection->executeStatement('DELETE FROM billing.plan WHERE id = :id', ['id' => $id->value]);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Plan
    {
        $features = DbalValue::json($row['features']);
        $prices = [];
        foreach ($this->connection->fetchAllAssociative('SELECT * FROM billing.plan_price WHERE plan_id = :p AND active', ['p' => $row['id']]) as $price) {
            $interval = BillingInterval::from(DbalValue::string($price['interval']));
            $prices[$interval->value] = new PlanPrice(DbalValue::string($price['id']), $interval, DbalValue::int($price['amount_cents']), new \DateTimeImmutable(DbalValue::string($price['created_at'])), DbalValue::nullableString($price['stripe_price_id']));
        }
        $nullableInt = static fn (mixed $v): ?int => null === $v ? null : DbalValue::int($v);

        return Plan::restore(
            new PlanId(DbalValue::string($row['id'])),
            DbalValue::string($row['slug']),
            new PlanTerms(
                DbalValue::string($row['name']),
                DbalValue::nullableString($row['description']),
                $nullableInt($row['quotes_per_period']),
                true === $row['unlimited'],
                $nullableInt($row['fair_use_cap']),
                array_values(array_filter(\is_array($features) ? $features : [], \is_string(...))),
                $nullableInt($row['max_offers']),
                true === $row['featured'],
                true === $row['advanced_stats'],
                DbalValue::nullableString($row['badge']),
                PlanVisibility::from(DbalValue::string($row['visibility'])),
                DbalValue::nullableString($row['access_code']),
            ),
            DbalValue::int($row['position']),
            PlanStatus::from(DbalValue::string($row['status'])),
            $prices,
            null === $row['published_at'] ? null : new \DateTimeImmutable(DbalValue::string($row['published_at'])),
            true === $row['ever_published'],
            DbalValue::nullableString($row['stripe_product_id']),
            new \DateTimeImmutable(DbalValue::string($row['created_at'])),
            new \DateTimeImmutable(DbalValue::string($row['updated_at'])),
        );
    }
}
