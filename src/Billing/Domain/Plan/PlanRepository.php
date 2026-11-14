<?php

declare(strict_types=1);

namespace App\Billing\Domain\Plan;

interface PlanRepository
{
    public function find(PlanId $id): ?Plan;

    public function findBySlug(string $slug): ?Plan;

    /** @return list<Plan> par ordre d'affichage */
    public function all(): array;

    /** Abonnés en vigueur au forfait (actifs, en retard ou suspendus). */
    public function subscribers(PlanId $id): int;

    public function save(Plan $plan): void;

    public function delete(PlanId $id): void;
}
