<?php

declare(strict_types=1);

namespace App\Tests\Integration\ArtisanProfile;

use App\ArtisanProfile\Application\Agenda\AddAbsence;
use App\ArtisanProfile\Application\Agenda\PauseAvailability;
use App\ArtisanProfile\Application\Agenda\RefreshAgendas;
use App\ArtisanProfile\Application\Agenda\RemoveAbsence;
use App\ArtisanProfile\Application\Agenda\SaveAgendaSettings;
use App\ArtisanProfile\Application\Agenda\SaveWeeklyRules;
use App\ArtisanProfile\Application\Port\AgendaStore;
use App\ArtisanProfile\Domain\Agenda\AgendaRuleViolation;
use App\ArtisanProfile\Domain\Agenda\FrenchHolidays;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** T-702a — plages récurrentes, réglages, absences et jours fériés → créneaux ouverts générés ; créneaux réservés préservés. */
final class AgendaTest extends KernelTestCase
{
    use ArtisanDossier;

    private const string ABSENCE = '0190f5e4-0000-7000-8000-0000000ae001';

    private Connection $connection;
    private string $artisan;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $this->connection = $connection;
        $this->prepareReferentials();
        $this->artisan = $this->submittedDossier();
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    public function testRulesSettingsAbsencesAndHolidaysShapeOpenSlots(): void
    {
        $this->dossierDispatch(new SaveWeeklyRules(self::DOSSIER_USER, [
            ['weekday' => 1, 'start' => '08:00', 'end' => '12:00'],
            ['weekday' => 3, 'start' => '14:00', 'end' => '16:00'],
        ]));
        $slots = $this->slots();
        self::assertNotEmpty($slots);
        foreach ($slots as [$start, $end]) {
            $local = $start->setTimezone(new \DateTimeZone('Europe/Paris'));
            self::assertContains((int) $local->format('N'), [1, 3]);
            self::assertFalse(FrenchHolidays::isHoliday($local), 'fermé les jours fériés par défaut');
            self::assertSame(3600, $end->getTimestamp() - $start->getTimestamp());
        }
        $hourly = \count($slots);

        $this->dossierDispatch(new SaveAgendaSettings(self::DOSSIER_USER, 120, 48, true));
        self::assertCount(intdiv($hourly, 2), $this->slots(), 'créneaux de 2 h');

        // Congé sur la semaine prochaine (lundi → dimanche).
        $monday = new \DateTimeImmutable('monday next week', new \DateTimeZone('Europe/Paris'));
        $this->dossierDispatch(new AddAbsence(self::DOSSIER_USER, self::ABSENCE, $monday->format('Y-m-d'), $monday->modify('+6 days')->format('Y-m-d'), 'leave', 'Congés (fictif)'));
        $week = array_filter($this->slots(), static fn (array $s): bool => $s[0]->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y-m-d') >= $monday->format('Y-m-d') && $s[0]->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y-m-d') <= $monday->modify('+6 days')->format('Y-m-d'));
        self::assertSame([], $week);

        try {
            $this->dossierDispatch(new AddAbsence(self::DOSSIER_USER, '0190f5e4-0000-7000-8000-0000000ae002', $monday->modify('+2 days')->format('Y-m-d'), $monday->modify('+9 days')->format('Y-m-d'), 'unavailable'));
            self::fail('absence chevauchante acceptée');
        } catch (AgendaRuleViolation) {
        }
        $this->dossierDispatch(new RemoveAbsence(self::DOSSIER_USER, self::ABSENCE));
        self::assertCount(intdiv($hourly, 2), $this->slots());

        $this->dossierDispatch(new PauseAvailability(self::DOSSIER_USER, true));
        $store = self::getContainer()->get(AgendaStore::class);
        \assert($store instanceof AgendaStore);
        self::assertTrue($store->settings($this->artisan)->paused);
        self::assertSame(120, $store->settings($this->artisan)->slotMinutes, 'la pause conserve les autres réglages');
    }

    public function testBookedSlotsSurviveRegenerationAndOverlapsAreImpossible(): void
    {
        $this->dossierDispatch(new SaveWeeklyRules(self::DOSSIER_USER, [['weekday' => 2, 'start' => '09:00', 'end' => '12:00']]));
        $first = $this->slots()[0];
        // Un rendez-vous (lot RDV) retient le premier créneau.
        $this->connection->executeStatement("UPDATE artisan.availability_slot SET status = 'booked' WHERE artisan_id = :a AND starts_at = :s", ['a' => $this->artisan, 's' => $first[0]->format(\DATE_ATOM)]);
        $this->dossierDispatch(new SaveWeeklyRules(self::DOSSIER_USER, [['weekday' => 2, 'start' => '08:30', 'end' => '12:00']]));
        self::assertSame(1, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM artisan.availability_slot WHERE status = 'booked'")));
        foreach ($this->slots() as [$start, $end]) {
            self::assertTrue($end <= $first[0] || $start >= $first[1], 'aucun créneau ouvert ne chevauche le créneau réservé');
        }

        $this->expectException(\Doctrine\DBAL\Exception::class);
        $this->connection->executeStatement('INSERT INTO artisan.availability_slot (id, artisan_id, starts_at, ends_at) VALUES (:id, :a, :s, :e)', ['id' => '0190f5e4-0000-7000-8000-0000000ae0ff', 'a' => $this->artisan, 's' => $first[0]->format(\DATE_ATOM), 'e' => $first[1]->format(\DATE_ATOM)]);
    }

    public function testNightlyRefreshPurgesPastSlotsAndInvalidRulesAreRefused(): void
    {
        $this->dossierDispatch(new SaveWeeklyRules(self::DOSSIER_USER, [['weekday' => 5, 'start' => '08:00', 'end' => '10:00']]));
        $this->connection->executeStatement("INSERT INTO artisan.availability_slot (id, artisan_id, starts_at, ends_at) VALUES ('0190f5e4-0000-7000-8000-0000000ae0f1', :a, now() - interval '3 days', now() - interval '3 days' + interval '1 hour')", ['a' => $this->artisan]);
        $this->dossierDispatch(new RefreshAgendas());
        self::assertSame(0, DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM artisan.availability_slot WHERE ends_at < now()')));

        $this->expectException(AgendaRuleViolation::class);
        $this->dossierDispatch(new SaveWeeklyRules(self::DOSSIER_USER, [['weekday' => 5, 'start' => '08:00', 'end' => '12:00'], ['weekday' => 5, 'start' => '11:00', 'end' => '13:00']]));
    }

    /** @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> */
    private function slots(): array
    {
        return array_map(
            static fn (array $r): array => [new \DateTimeImmutable(DbalValue::string($r['starts_at'])), new \DateTimeImmutable(DbalValue::string($r['ends_at']))],
            $this->connection->fetchAllAssociative("SELECT starts_at, ends_at FROM artisan.availability_slot WHERE artisan_id = :a AND status = 'open' ORDER BY starts_at", ['a' => $this->artisan]),
        );
    }
}
