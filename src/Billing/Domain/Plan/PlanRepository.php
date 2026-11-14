<?php

declare(strict_types=1);

namespace App\Billing\Domain\Plan;

interface PlanRepository
{
    public function find(PlanId $id): ?Plan;

    public function findBySlug(string $slug): ?Plan;

    /** @return list<Plan> par ordre d'affichage */
    public function all(): array;

    /** Abonnés actifs au forfait (0 tant que les abonnements n'existent pas, T-603). */
    public function subscribers(PlanId $id): int;

    public function save(Plan $plan): void;

    public function delete(PlanId $id): void;
}
