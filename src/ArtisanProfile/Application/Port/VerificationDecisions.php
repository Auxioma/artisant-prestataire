<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Port;

/** RG-130 — journal des décisions de vérification (ajout seul : horodatage et auteur). */
interface VerificationDecisions
{
    public function record(string $id, string $artisanId, string $adminId, string $decision, ?string $reasonCode, ?string $comment, \DateTimeImmutable $at): void;

    /** @return list<array{decision: string, reason_code: ?string, comment: ?string, admin_id: string, created_at: \DateTimeImmutable}> plus récentes d'abord */
    public function history(string $artisanId): array;
}
