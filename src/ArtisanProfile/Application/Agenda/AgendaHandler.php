<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Agenda;

use App\ArtisanProfile\Application\Port\AgendaStore;
use App\ArtisanProfile\Domain\Agenda\Absence;
use App\ArtisanProfile\Domain\Agenda\AbsenceKind;
use App\ArtisanProfile\Domain\Agenda\AgendaRuleViolation;
use App\ArtisanProfile\Domain\Agenda\AgendaSettings;
use App\ArtisanProfile\Domain\Agenda\Event\AgendaChanged;
use App\ArtisanProfile\Domain\Agenda\SlotPlanner;
use App\ArtisanProfile\Domain\Agenda\WeeklyRule;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/**
 * PR06 / PR16 / PR19 — agenda de l'artisan. Chaque modification régénère les créneaux ouverts sur l'horizon
 * `availability.horizon_days` (les créneaux retenus ou réservés restent) puis publie `AgendaChanged` (recherche,
 * badge « Disponible » RG-013).
 */
final readonly class AgendaHandler implements CommandHandler
{
    /** Plages récurrentes au plus par artisan (deux par jour et plus). */
    public const int MAX_RULES = 21;
    /** Les créneaux passés de plus d'un jour sont purgés. */
    private const string PURGE_AFTER = '-1 day';

    public function __construct(
        private AgendaStore $store,
        private SettingsProvider $settings,
        private EventBus $events,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(SaveWeeklyRules|SaveAgendaSettings|PauseAvailability|AddAbsence|RemoveAbsence|RefreshAgendas $command): void
    {
        if ($command instanceof RefreshAgendas) {
            foreach ($this->store->artisansWithRules() as $artisanId) {
                $this->regenerate($artisanId);
            }
            $this->store->purgeOpenSlotsBefore($this->clock->now()->modify(self::PURGE_AFTER));

            return;
        }
        $artisanId = $this->store->artisanOfUser($command->userId) ?? throw new AgendaRuleViolation('Agenda réservé aux professionnels.');
        $now = $this->clock->now();
        if ($command instanceof SaveWeeklyRules) {
            $this->rules($artisanId, $command);
        } elseif ($command instanceof SaveAgendaSettings) {
            $this->store->saveSettings($artisanId, new AgendaSettings($command->slotMinutes, $command->minNoticeHours, $command->closedOnHolidays, $this->store->settings($artisanId)->paused), $now);
        } elseif ($command instanceof PauseAvailability) {
            $this->store->saveSettings($artisanId, $this->store->settings($artisanId)->withPaused($command->paused), $now);
        } elseif ($command instanceof AddAbsence) {
            $this->absence($artisanId, $command, $now);
        } elseif (!$this->store->removeAbsence($artisanId, $command->absenceId)) {
            throw new AgendaRuleViolation('Absence introuvable.');
        }
        $this->regenerate($artisanId);
    }

    private function rules(string $artisanId, SaveWeeklyRules $command): void
    {
        if (\count($command->rules) > self::MAX_RULES) {
            throw new AgendaRuleViolation(\sprintf('%d plages au plus.', self::MAX_RULES));
        }
        $rules = WeeklyRule::normalize(array_map(static fn (array $r): WeeklyRule => WeeklyRule::fromTimes($r['weekday'], $r['start'], $r['end']), $command->rules));
        $this->store->replaceRules($artisanId, $rules, array_map(fn (): string => $this->ids->next(), $rules));
    }

    private function absence(string $artisanId, AddAbsence $command, \DateTimeImmutable $now): void
    {
        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', $command->from);
        $to = \DateTimeImmutable::createFromFormat('!Y-m-d', $command->to);
        $kind = AbsenceKind::tryFrom($command->kind);
        if (false === $from || false === $to || null === $kind) {
            throw new AgendaRuleViolation('Dates ou type d’absence invalides.');
        }
        if ($to->format('Y-m-d') < $now->setTimezone(new \DateTimeZone(SlotPlanner::ZONE))->format('Y-m-d')) {
            throw new AgendaRuleViolation('Cette absence est déjà passée.');
        }
        $label = null === $command->label || '' === trim($command->label) ? null : trim($command->label);
        if (!$this->store->addAbsence($artisanId, new Absence($command->absenceId, $from, $to, $kind, $label), $now)) {
            throw new AgendaRuleViolation('Une absence couvre déjà une partie de ces dates.');
        }
    }

    private function regenerate(string $artisanId): void
    {
        $now = $this->clock->now();
        $today = $now->setTimezone(new \DateTimeZone(SlotPlanner::ZONE))->setTime(0, 0);
        $last = $today->modify(\sprintf('+%d days', $this->settings->int(SettingKey::AvailabilityHorizonDays)));
        $planned = SlotPlanner::plan(
            $this->store->rules($artisanId),
            $this->store->absences($artisanId, $today),
            $this->store->settings($artisanId),
            $this->store->busy($artisanId, $today, $last->modify('+1 day')),
            $today,
            $last,
        );
        $this->store->replaceOpenSlots($artisanId, $today, array_map(fn (array $s): array => [$this->ids->next(), $s[0], $s[1]], $planned));
        $this->events->publish(new AgendaChanged($artisanId, $now));
    }
}
