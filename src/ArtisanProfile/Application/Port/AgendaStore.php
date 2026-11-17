<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Port;

use App\ArtisanProfile\Domain\Agenda\Absence;
use App\ArtisanProfile\Domain\Agenda\AgendaSettings;
use App\ArtisanProfile\Domain\Agenda\WeeklyRule;

/** Agenda d'un artisan (`artisan.agenda_settings`, `availability_rule`, `availability_absence`, `availability_slot`). */
interface AgendaStore
{
    /** Profil de l'artisan connecté, ou null pour un autre compte. */
    public function artisanOfUser(string $userId): ?string;

    public function settings(string $artisanId): AgendaSettings;

    public function saveSettings(string $artisanId, AgendaSettings $settings, \DateTimeImmutable $at): void;

    /** @return list<WeeklyRule> */
    public function rules(string $artisanId): array;

    /**
     * Remplace toutes les plages récurrentes.
     *
     * @param list<WeeklyRule> $rules
     * @param list<string>     $ids   un identifiant par plage
     */
    public function replaceRules(string $artisanId, array $rules, array $ids): void;

    /**
     * Absences qui se terminent à partir de `$since` (toutes si null), par date de début.
     *
     * @return list<Absence>
     */
    public function absences(string $artisanId, ?\DateTimeImmutable $since = null): array;

    /** Faux si une absence chevauche déjà ces dates. */
    public function addAbsence(string $artisanId, Absence $absence, \DateTimeImmutable $at): bool;

    public function removeAbsence(string $artisanId, string $absenceId): bool;

    /**
     * Créneaux retenus ou réservés (non ouverts) entre deux instants : ils ne sont jamais régénérés.
     *
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>
     */
    public function busy(string $artisanId, \DateTimeImmutable $from, \DateTimeImmutable $to): array;

    /**
     * Remplace les créneaux ouverts à partir de `$from` par ceux fournis.
     *
     * @param list<array{0: string, 1: \DateTimeImmutable, 2: \DateTimeImmutable}> $slots [id, début, fin]
     */
    public function replaceOpenSlots(string $artisanId, \DateTimeImmutable $from, array $slots): void;

    /**
     * Tous les créneaux (ouverts, retenus, réservés, bloqués) entre deux instants.
     *
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: string}> [début, fin, statut]
     */
    public function slots(string $artisanId, \DateTimeImmutable $from, \DateTimeImmutable $to): array;

    /** RG-013 : un créneau ouvert dans les `$days` prochains jours, prévenance comprise, et l'artisan n'est pas en pause. */
    public function availableSoon(string $artisanId, int $days): bool;

    /** PR06.3 — empreinte SHA-256 du jeton du lien iCal privé (null : lien révoqué). */
    public function saveIcalToken(string $artisanId, ?string $hash, \DateTimeImmutable $at): void;

    /** Date de création du lien iCal en service, ou null. */
    public function icalTokenCreatedAt(string $artisanId): ?\DateTimeImmutable;

    /** @return array{artisan_id: string, name: string}|null artisan dont le lien iCal porte cette empreinte */
    public function artisanByIcalHash(string $hash): ?array;

    /** @return list<string> artisans qui ont au moins une plage récurrente */
    public function artisansWithRules(): array;

    /** Supprime les créneaux ouverts terminés avant `$before` ; renvoie leur nombre. */
    public function purgeOpenSlotsBefore(\DateTimeImmutable $before): int;
}
