<?php

declare(strict_types=1);

namespace App\Tests\Integration\Quote;

use App\ArtisanProfile\Application\Agenda\SaveWeeklyRules;
use App\ArtisanProfile\Application\Agenda\SlotReservations;
use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\Billing\Application\Credit\CreditLedger;
use App\Identity\Application\Command\RegisterUser;
use App\Quote\Application\Appointment\AppointmentDecision;
use App\Quote\Application\Appointment\ExpireAppointmentProposals;
use App\Quote\Application\Appointment\ProposeAppointment;
use App\Quote\Application\Appointment\SendAppointmentReminders;
use App\Quote\Application\Client\AcceptQuote;
use App\Quote\Application\Editor\SaveQuoteDraft;
use App\Quote\Application\EventHandler\NotifyAppointmentChange;
use App\Quote\Application\Sending\SendQuote;
use App\Quote\Domain\Appointment\AppointmentRuleViolation;
use App\Quote\Domain\Appointment\Event\AppointmentChanged;
use App\QuoteRequest\Application\Dispatch\DispatchQuoteRequest;
use App\QuoteRequest\Application\Submission\SubmitQuoteRequest;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * T-703a — rendez-vous d'un devis accepté : proposition sur un créneau ouvert (prévenance), verrou RG-073 puis
 * réservation, confirmation par l'autre partie, report, annulation motivée, expiration, réalisation → demande terminée,
 * rappel J-1.
 */
final class AppointmentTest extends KernelTestCase
{
    use ArtisanDossier;

    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000fe701';
    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000ae701';
    private const string REQUEST = '0190f5e4-0000-7000-8000-0000000fe702';
    private const string QUOTE = '0190f5e4-0000-7000-8000-0000000fe703';
    private const string A1 = '0190f5e4-0000-7000-8000-0000000fe7a1';
    private const string A2 = '0190f5e4-0000-7000-8000-0000000fe7a2';
    private const string A3 = '0190f5e4-0000-7000-8000-0000000fe7a3';

    private Connection $connection;
    private string $artisan;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $this->connection = $connection;
        $this->prepareReferentials();
        $this->artisan = $this->submittedDossier(200);
        $this->uploadPiece('kbis');
        $this->uploadPiece('insurance_rc');
        $this->dossierDispatch(new ApproveArtisan($this->artisan, self::ADMIN));
        $this->dossierDispatch(new RegisterUser(self::CLIENT, 'camille.rdv@exemple.test', 'cheval agrafe batterie', 'client', 'Camille', 'Fictive'));
        $connection->executeStatement("UPDATE identity.user_account SET status = 'active', email_verified_at = date_trunc('second', now()) WHERE id IN (:a, :b)", ['a' => self::DOSSIER_USER, 'b' => self::CLIENT]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $job = $this->dossierValue("SELECT id FROM catalog.job WHERE slug = 'plombier'");
        $this->dossierDispatch(new SubmitQuoteRequest(self::REQUEST, self::CLIENT, $job, '76351', '76600', '1 rue Fictive', 'repair', 'Fuite sous l’évier de la cuisine depuis ce matin (fictif).', [], '500_1000', 'week', null, title: 'Fuite sous l’évier'));
        $this->dossierDispatch(new DispatchQuoteRequest(self::REQUEST));
        $this->dossierDispatch(new SaveQuoteDraft(self::DOSSIER_USER, self::REQUEST, self::QUOTE, ['subject' => 'Réparation de la fuite', 'lines' => [
            ['type' => 'labour', 'label' => 'Recherche de fuite', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '90', 'vat' => '1000'],
            ['type' => 'travel', 'label' => 'Déplacement', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '0', 'vat' => '2000'],
        ]]));
        $ledger = self::getContainer()->get(CreditLedger::class);
        \assert($ledger instanceof CreditLedger);
        $ledger->grantFree('0190f5e4-0000-7000-8000-0000000fe705', $this->artisan, self::DOSSIER_USER, 3, null, new \DateTimeImmutable());
        $this->dossierDispatch(new SendQuote(self::DOSSIER_USER, self::REQUEST));
        $this->dossierDispatch(new AcceptQuote(self::CLIENT, self::QUOTE));
        $this->dossierDispatch(new SaveWeeklyRules(self::DOSSIER_USER, array_map(static fn (int $d): array => ['weekday' => $d, 'start' => '08:00', 'end' => '12:00'], range(1, 7))));
        $this->connection->executeStatement('UPDATE artisan.agenda_settings SET closed_on_holidays = false WHERE artisan_id = :a', ['a' => $this->artisan]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    public function testProposeConfirmRescheduleCancel(): void
    {
        [$first, $second] = $this->openSlots();
        $this->dossierDispatch(new ProposeAppointment(self::CLIENT, self::A1, self::QUOTE, $first->format(\DATE_ATOM), 'Code portail 1234 (fictif)'));
        self::assertSame('held', $this->slotStatus($first));
        $hold = new \DateTimeImmutable(DbalValue::string($this->connection->fetchOne('SELECT hold_until FROM quote.appointment WHERE id = :id', ['id' => self::A1])));
        self::assertEqualsWithDelta(new \DateTimeImmutable('+24 hours')->getTimestamp(), min($hold, $first)->getTimestamp(), 120 + max(0, new \DateTimeImmutable('+24 hours')->getTimestamp() - $first->getTimestamp()));

        $this->expectViolation(fn () => $this->dossierDispatch(new ProposeAppointment(self::CLIENT, self::A2, self::QUOTE, $second->format(\DATE_ATOM))), 'déjà prévu');
        $this->expectViolation(fn () => $this->dossierDispatch(new AppointmentDecision(self::CLIENT, self::A1, AppointmentDecision::CONFIRM)), 'attend la confirmation');

        $this->dossierDispatch(new AppointmentDecision(self::DOSSIER_USER, self::A1, AppointmentDecision::CONFIRM));
        self::assertSame('confirmed', $this->stateOf(self::A1));
        self::assertSame('booked', $this->slotStatus($first));

        // E-36 à l'artisan pour la proposition du client, E-14 au client pour la confirmation de l'artisan.
        $notify = self::getContainer()->get(NotifyAppointmentChange::class);
        \assert($notify instanceof NotifyAppointmentChange);
        $notify(new AppointmentChanged(self::A1, self::QUOTE, 'proposed', 'client', null, new \DateTimeImmutable()));
        $notify(new AppointmentChanged(self::A1, self::QUOTE, 'confirmed', 'artisan', null, new \DateTimeImmutable()));
        self::assertStringContainsString('Camille vous propose un rendez-vous', DbalValue::string($this->connection->fetchOne("SELECT body FROM notification.in_app WHERE code = 'E-36'")));
        self::assertStringContainsString('a confirmé le rendez-vous', DbalValue::string($this->connection->fetchOne("SELECT body FROM notification.in_app WHERE code = 'E-14'")));
        self::assertSame(1, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM notification.delivery WHERE code = 'E-14' AND channel = 'email'")));

        // Report par l'artisan : l'ancien créneau est rouvert, le nouveau retenu.
        $this->dossierDispatch(new ProposeAppointment(self::DOSSIER_USER, self::A2, self::QUOTE, $second->format(\DATE_ATOM), null, self::A1));
        self::assertSame('rescheduled', $this->stateOf(self::A1));
        self::assertSame('open', $this->slotStatus($first));
        self::assertSame('held', $this->slotStatus($second));

        $this->expectViolation(fn () => $this->dossierDispatch(new AppointmentDecision(self::CLIENT, self::A2, AppointmentDecision::CANCEL, ' ')), 'motif');
        $this->dossierDispatch(new AppointmentDecision(self::CLIENT, self::A2, AppointmentDecision::CANCEL, 'Empêchement (fictif)'));
        self::assertSame('cancelled', $this->stateOf(self::A2));
        self::assertSame('open', $this->slotStatus($second));
    }

    public function testNoticeExpiryCompletionAndReminder(): void
    {
        // Prévenance de 24 h : un créneau plus proche est refusé au client.
        $this->connection->executeStatement("DELETE FROM artisan.availability_slot WHERE artisan_id = :a AND tstzrange(starts_at, ends_at) && tstzrange(date_trunc('hour', now()) + interval '3 hours', date_trunc('hour', now()) + interval '4 hours')", ['a' => $this->artisan]);
        $this->connection->executeStatement("INSERT INTO artisan.availability_slot (id, artisan_id, starts_at, ends_at) VALUES ('0190f5e4-0000-7000-8000-0000000fe7f1', :a, date_trunc('hour', now()) + interval '3 hours', date_trunc('hour', now()) + interval '4 hours')", ['a' => $this->artisan]);
        $soon = new \DateTimeImmutable(DbalValue::string($this->connection->fetchOne("SELECT starts_at FROM artisan.availability_slot WHERE id = '0190f5e4-0000-7000-8000-0000000fe7f1'")));
        $this->expectViolation(fn () => $this->dossierDispatch(new ProposeAppointment(self::CLIENT, self::A1, self::QUOTE, $soon->format(\DATE_ATOM))), 'trop proche');

        // RG-073 : proposition non confirmée → expirée, créneau rouvert.
        [$first] = $this->openSlots();
        $this->dossierDispatch(new ProposeAppointment(self::CLIENT, self::A1, self::QUOTE, $first->format(\DATE_ATOM)));
        $this->connection->executeStatement("UPDATE quote.appointment SET hold_until = now() - interval '1 minute' WHERE id = :id", ['id' => self::A1]);
        $this->dossierDispatch(new ExpireAppointmentProposals());
        self::assertSame('expired', $this->stateOf(self::A1));
        self::assertSame('open', $this->slotStatus($first));

        // Réalisation après l'heure : demande « terminée ».
        $this->connection->executeStatement("INSERT INTO quote.appointment (id, quote_id, starts_at, ends_at, status, proposed_by, confirmed_at, created_at) VALUES (:id, :q, now() - interval '3 hours', now() - interval '2 hours', 'confirmed', 'client', now() - interval '2 days', now() - interval '3 days')", ['id' => self::A3, 'q' => self::QUOTE]);
        $this->dossierDispatch(new AppointmentDecision(self::CLIENT, self::A3, AppointmentDecision::COMPLETE));
        self::assertSame('completed', $this->stateOf(self::A3));
        self::assertSame('completed', $this->connection->fetchOne('SELECT status FROM request.quote_request WHERE id = :id', ['id' => self::REQUEST]));

        // Rappel J-1 : envoyé une fois.
        $this->connection->executeStatement("UPDATE quote.appointment SET status = 'confirmed', completed_at = NULL, starts_at = (date_trunc('day', now() AT TIME ZONE 'Europe/Paris') + interval '1 day 10 hours') AT TIME ZONE 'Europe/Paris', ends_at = (date_trunc('day', now() AT TIME ZONE 'Europe/Paris') + interval '1 day 11 hours') AT TIME ZONE 'Europe/Paris' WHERE id = :id", ['id' => self::A3]);
        $this->dossierDispatch(new SendAppointmentReminders());
        self::assertNotNull($this->connection->fetchOne('SELECT reminded_at FROM quote.appointment WHERE id = :id', ['id' => self::A3]));
    }

    public function testStrangersAndUnacceptedQuotesAreRefused(): void
    {
        [$first] = $this->openSlots();
        $this->expectViolation(fn () => $this->dossierDispatch(new ProposeAppointment('0190f5e4-0000-7000-8000-0000000fe799', self::A1, self::QUOTE, $first->format(\DATE_ATOM))), 'introuvable');
        $this->connection->executeStatement("UPDATE quote.quote SET status = 'sent' WHERE id = :q", ['q' => self::QUOTE]);
        $this->expectViolation(fn () => $this->dossierDispatch(new ProposeAppointment(self::CLIENT, self::A1, self::QUOTE, $first->format(\DATE_ATOM))), 'devis accepté');
    }

    /** @return list<\DateTimeImmutable> */
    private function openSlots(): array
    {
        $slots = self::getContainer()->get(SlotReservations::class);
        \assert($slots instanceof SlotReservations);

        return array_map(static fn (array $s): \DateTimeImmutable => $s[0], $slots->openSlots($this->artisan, new \DateTimeImmutable('+2 days'), new \DateTimeImmutable('+20 days')));
    }

    private function slotStatus(\DateTimeImmutable $start): string
    {
        return DbalValue::string($this->connection->fetchOne('SELECT status FROM artisan.availability_slot WHERE artisan_id = :a AND starts_at = :s', ['a' => $this->artisan, 's' => $start->format(\DATE_ATOM)]));
    }

    private function stateOf(string $id): string
    {
        return DbalValue::string($this->connection->fetchOne('SELECT status FROM quote.appointment WHERE id = :id', ['id' => $id]));
    }

    private function expectViolation(callable $action, string $message): void
    {
        try {
            $action();
            self::fail('action acceptée à tort');
        } catch (AppointmentRuleViolation $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }
}
