<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Port;

use App\QuoteRequest\Application\Dispatch\DispatchReason;
use App\QuoteRequest\Application\Dispatch\DispatchSummary;
use App\QuoteRequest\Application\Dispatch\PendingDigest;

/** `request.request_dispatch` — un artisan reçoit une demande au plus une fois (base de PR02). */
interface RequestDispatches
{
    /**
     * Ajoute l'artisan aux destinataires ; `false` s'il l'était déjà (diffusion rejouée sans double alerte).
     * `$notified` : alerte envoyée tout de suite ; sinon, elle attend le digest de l'artisan.
     */
    public function record(string $requestId, string $artisanId, DispatchReason $reason, \DateTimeImmutable $at, bool $notified): bool;

    public function countFor(string $requestId): int;

    public function summary(string $requestId): ?DispatchSummary;

    /** @return list<PendingDigest> alertes en attente, par artisan, pour les demandes encore ouvertes */
    public function pendingDigests(): array;

    /** @param list<string> $requestIds */
    public function markNotified(string $artisanId, array $requestIds, \DateTimeImmutable $at): void;
}
