<?php

declare(strict_types=1);

namespace App\Tests\Unit\ArtisanProfile;

use App\ArtisanProfile\Domain\Agenda\Absence;
use App\ArtisanProfile\Domain\Agenda\AbsenceKind;
use App\ArtisanProfile\Domain\Agenda\AgendaRuleViolation;
use App\ArtisanProfile\Domain\Agenda\AgendaSettings;
use App\ArtisanProfile\Domain\Agenda\FrenchHolidays;
use App\ArtisanProfile\Domain\Agenda\SlotPlanner;
use App\ArtisanProfile\Domain\Agenda\WeeklyRule;
use PHPUnit\Framework\TestCase;

final class SlotPlannerTest extends TestCase
{
    public function testHolidaysIncludeEasterBasedDays(): void
    {
        $days = FrenchHolidays::of(2027);
        self::assertCount(11, $days);
        self::assertSame('Lundi de Pâques', $days['2027-03-29']);
        self::assertSame('Ascension', $days['2027-05-06']);
        self::assertSame('Lundi de Pentecôte', $days['2027-05-17']);
        self::assertTrue(FrenchHolidays::isHoliday(new \DateTimeImmutable('2026-11-11')));
        self::assertFalse(FrenchHolidays::isHoliday(new \DateTimeImmutable('2026-11-12')));
    }

    public function testRulesAreCutIntoSlotsInLocalTime(): void
    {
        // Lundi 16 nov. 2026 (heure d'hiver : UTC+1) : 8 h – 12 h en créneaux de 2 h, le reste de la semaine fermé.
        $slots = SlotPlanner::plan([WeeklyRule::fromTimes(1, '08:00', '12:00'), WeeklyRule::fromTimes(1, '13:30', '15:00')], [], new AgendaSettings(120), [], new \DateTimeImmutable('2026-11-16'), new \DateTimeImmutable('2026-11-22'));
        self::assertSame(['2026-11-16T07:00:00+00:00', '2026-11-16T09:00:00+00:00'], array_map(static fn (array $s): string => $s[0]->format(\DATE_ATOM), $slots), 'la plage de 1 h 30 ne contient aucun créneau de 2 h');
        self::assertSame('2026-11-16T11:00:00+00:00', $slots[1][1]->format(\DATE_ATOM));
    }

    public function testSummerTimeShiftsUtc(): void
    {
        $slots = SlotPlanner::plan([WeeklyRule::fromTimes(1, '08:00', '09:00')], [], new AgendaSettings(60, closedOnHolidays: false), [], new \DateTimeImmutable('2027-06-07'), new \DateTimeImmutable('2027-06-07'));
        self::assertSame('2027-06-07T06:00:00+00:00', $slots[0][0]->format(\DATE_ATOM));
    }

    public function testAbsencesHolidaysAndBusyRangesCloseSlots(): void
    {
        $rules = [WeeklyRule::fromTimes(2, '08:00', '10:00'), WeeklyRule::fromTimes(3, '08:00', '10:00'), WeeklyRule::fromTimes(4, '08:00', '10:00')];
        $absence = new Absence('0190f5e4-0000-7000-8000-00000000a001', new \DateTimeImmutable('2026-11-12'), new \DateTimeImmutable('2026-11-12'), AbsenceKind::Leave, null);
        $busy = [[new \DateTimeImmutable('2026-11-10T07:30:00Z'), new \DateTimeImmutable('2026-11-10T08:00:00Z')]];
        // Mar. 10 : 8 h occupé partiellement → seul 9 h reste ; mer. 11 : férié ; jeu. 12 : congé.
        $slots = SlotPlanner::plan($rules, [$absence], new AgendaSettings(60), $busy, new \DateTimeImmutable('2026-11-10'), new \DateTimeImmutable('2026-11-12'));
        self::assertSame(['2026-11-10T08:00:00+00:00'], array_map(static fn (array $s): string => $s[0]->format(\DATE_ATOM), $slots));

        $open = SlotPlanner::plan($rules, [], new AgendaSettings(60, closedOnHolidays: false), [], new \DateTimeImmutable('2026-11-11'), new \DateTimeImmutable('2026-11-11'));
        self::assertCount(2, $open, 'férié travaillé sur choix de l’artisan');
    }

    public function testOverlappingRulesAreRefused(): void
    {
        $this->expectException(AgendaRuleViolation::class);
        WeeklyRule::normalize([WeeklyRule::fromTimes(5, '08:00', '12:00'), WeeklyRule::fromTimes(5, '11:00', '13:00')]);
    }

    public function testInvalidValuesAreRefused(): void
    {
        foreach ([static fn () => WeeklyRule::fromTimes(1, '12:00', '08:00'), static fn () => WeeklyRule::fromTimes(1, '08:10', '09:00'), static fn () => new AgendaSettings(90), static fn () => new AgendaSettings(60, 500)] as $case) {
            try {
                $case();
                self::fail('valeur acceptée à tort');
            } catch (AgendaRuleViolation) {
                self::addToAssertionCount(1);
            }
        }
    }
}
