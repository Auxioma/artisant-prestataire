<?php

declare(strict_types=1);

namespace App\Billing\Application\Reconciliation;

/** Lectures et écritures du rapprochement (`billing.reconciliation_issue`, `billing.reconciliation_run`). */
interface ReconciliationStore
{
    /** @return array<string, array{status: string, period_end: ?string}> abonnements liés à Stripe, par identifiant Stripe */
    public function localSubscriptions(): array;

    /** @return list<string> clients Stripe connus (artisans de la plateforme) */
    public function knownCustomers(): array;

    /** @return array<string, array{status: string, amount_paid: int, period_granted: bool}> factures locales, par identifiant Stripe */
    public function localInvoices(\DateTimeImmutable $since): array;

    /**
     * ADR-0006 — soldes dénormalisés différents de la somme du registre.
     *
     * @return list<array{artisan_id: string, bucket: string, balance: int, ledger: int}>
     */
    public function ledgerMismatches(): array;

    /** @return list<string> événements Stripe reçus et toujours non traités avant `$before` */
    public function stuckEvents(\DateTimeImmutable $before): array;

    /** Ouvre l'écart (ou le confirme s'il est déjà ouvert). */
    public function raise(string $id, string $kind, string $reference, string $details, \DateTimeImmutable $at): void;

    /**
     * Résout automatiquement les écarts ouverts de ces types qui n'ont pas été revus pendant ce passage.
     *
     * @param list<string> $kinds
     */
    public function resolveStale(array $kinds, \DateTimeImmutable $runStartedAt, \DateTimeImmutable $at): int;

    public function recordRun(string $id, \DateTimeImmutable $startedAt, \DateTimeImmutable $finishedAt, bool $stripeChecked, int $checked, int $issues): void;

    public function lastRun(): ?ReconciliationRun;

    /** @return list<ReconciliationIssue> */
    public function openIssues(): array;

    public function resolve(string $issueId, string $adminId, \DateTimeImmutable $at): bool;
}
