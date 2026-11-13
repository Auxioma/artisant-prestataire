<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Port;

use App\QuoteRequest\Application\Maintenance\DraftReminder;
use App\QuoteRequest\Application\Maintenance\ExpiredDraft;
use App\QuoteRequest\Application\Maintenance\ExpiryNotice;

/** Tâches planifiées des demandes (T-407) : échéances RG-064, relances E-19 / E-19c, purge RG-060. */
interface RequestMaintenanceStore
{
    public const string EXPIRY_WARNING = 'expiry_warning';
    public const string DRAFT_REMINDER = 'draft_reminder';

    /** @return list<string> demandes ouvertes ou complètes dont l'échéance est passée */
    public function dueForExpiry(\DateTimeImmutable $now, int $limit): array;

    /** @return list<ExpiryNotice> échéance avant `$until`, sans prévenance pour cette échéance (une relance la repousse) */
    public function expiringSoon(\DateTimeImmutable $now, \DateTimeImmutable $until, int $limit): array;

    /** @return list<DraftReminder> brouillons de compte non modifiés depuis `$idleSince`, non envoyés ni relancés */
    public function remindableDrafts(\DateTimeImmutable $idleSince, \DateTimeImmutable $now, int $limit): array;

    /** @return list<ExpiredDraft> */
    public function expiredDrafts(\DateTimeImmutable $now, int $limit): array;

    public function notice(string $requestId): ?ExpiryNotice;

    /** Enregistre une relance ; `false` si elle avait déjà été envoyée. */
    public function markSent(string $kind, string $subjectId, string $step, \DateTimeImmutable $at): bool;
}
