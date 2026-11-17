<?php

declare(strict_types=1);

namespace App\Quote\Application\Appointment;

use App\ArtisanProfile\Application\Agenda\SlotReservations;
use App\Quote\Domain\Appointment\Appointment;
use App\Quote\Domain\Appointment\AppointmentId;
use App\Quote\Domain\Appointment\AppointmentParty;
use App\Quote\Domain\Appointment\AppointmentRepository;
use App\Quote\Domain\Appointment\AppointmentRuleViolation;
use App\Quote\Domain\Appointment\AppointmentStatus;
use App\QuoteRequest\Domain\Request\QuoteRequestId;
use App\QuoteRequest\Domain\Request\QuoteRequestRepository;
use App\QuoteRequest\Domain\Request\RequestRuleViolation;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Time\Clock;

/**
 * C03.6 / PR06.2 — rendez-vous d'un devis accepté. Proposition sur un créneau ouvert (prévenance de l'artisan pour le
 * client), créneau retenu `appointments.hold_hours` (RG-073) puis réservé à la confirmation de l'autre partie ;
 * annulation motivée et report par le client jusqu'à 24 h avant, par l'artisan jusqu'au début ; réalisé après l'heure
 * de début → demande « terminée ».
 */
final readonly class AppointmentHandler implements CommandHandler
{
    /** Le client peut annuler ou reporter jusqu'à 24 h avant le rendez-vous (C10). */
    public const int CLIENT_CHANGE_HOURS = 24;
    private const int EXPIRY_BATCH = 200;

    public function __construct(
        private AppointmentRepository $appointments,
        private AppointmentParties $parties,
        private SlotReservations $slots,
        private QuoteRequestRepository $requests,
        private SettingsProvider $settings,
        private EventBus $events,
        private Clock $clock,
    ) {
    }

    public function __invoke(ProposeAppointment|AppointmentDecision|ExpireAppointmentProposals $command): void
    {
        $now = $this->clock->now();
        if ($command instanceof ExpireAppointmentProposals) {
            foreach ($this->appointments->expiredProposals($now, self::EXPIRY_BATCH) as $appointment) {
                $appointment->expire($now);
                $this->slots->release($appointment->id()->value, $now);
                $this->appointments->save($appointment);
                $this->events->publish(...$appointment->releaseEvents());
            }

            return;
        }
        if ($command instanceof ProposeAppointment) {
            $this->propose($command, $now);

            return;
        }
        $appointment = $this->appointments->findForUpdate(new AppointmentId($command->appointmentId)) ?? throw new AppointmentRuleViolation('Rendez-vous introuvable.');
        $parties = $this->parties->ofQuote($appointment->quoteId()) ?? throw new AppointmentRuleViolation('Rendez-vous introuvable.');
        $party = $this->party($command->userId, $parties);
        switch ($command->action) {
            case AppointmentDecision::CONFIRM:
                $appointment->confirm($party, $now);
                $this->slots->book($appointment->id()->value);
                break;
            case AppointmentDecision::CANCEL:
                $this->assertClientDeadline($party, $appointment, $now);
                $appointment->cancel($party, (string) $command->reason, $now);
                $this->slots->release($appointment->id()->value, $now);
                break;
            case AppointmentDecision::COMPLETE:
                $appointment->complete($party, $now);
                $this->completeRequest($parties['request_id'], $now);
                break;
            default:
                throw new AppointmentRuleViolation('Action inconnue.');
        }
        $this->appointments->save($appointment);
        $this->events->publish(...$appointment->releaseEvents());
    }

    private function propose(ProposeAppointment $command, \DateTimeImmutable $now): void
    {
        $parties = $this->parties->ofQuote($command->quoteId) ?? throw new AppointmentRuleViolation('Devis introuvable.');
        $party = $this->party($command->userId, $parties);
        if ('accepted' !== $parties['quote_status']) {
            throw new AppointmentRuleViolation('Un rendez-vous se prend une fois le devis accepté.');
        }
        $startsAt = \DateTimeImmutable::createFromFormat(\DATE_ATOM, $command->startsAt) ?: throw new AppointmentRuleViolation('Choisissez un créneau.');
        $current = $this->appointments->activeForQuote($command->quoteId);
        if (null !== $current) {
            if ($current->id()->value !== $command->replacesId) {
                throw new AppointmentRuleViolation('Un rendez-vous est déjà prévu : reprogrammez-le plutôt que d’en proposer un autre.');
            }
            $this->assertClientDeadline($party, $current, $now);
            $current->reschedule($party, $now);
            $this->slots->release($current->id()->value, $now);
            $this->appointments->save($current);
        }
        if (AppointmentParty::Client === $party && $startsAt < $now->modify(\sprintf('+%d hours', $this->slots->noticeHours($parties['artisan_id'])))) {
            throw new AppointmentRuleViolation('Ce créneau est trop proche : le professionnel demande à être prévenu plus tôt.');
        }
        $holdUntil = $now->modify(\sprintf('+%d hours', $this->settings->int(SettingKey::AppointmentsHoldHours)));
        $endsAt = $this->slots->hold($parties['artisan_id'], $startsAt, $command->appointmentId, min($holdUntil, $startsAt)) ?? throw new AppointmentRuleViolation('Ce créneau vient d’être pris ou n’est plus proposé : choisissez-en un autre.');
        $appointment = Appointment::propose(new AppointmentId($command->appointmentId), $command->quoteId, $startsAt, $endsAt, $party, $holdUntil, $command->note, $now);
        $this->appointments->save($appointment);
        $this->events->publish(...$appointment->releaseEvents());
    }

    /** @param array<string, string|null> $parties */
    private function party(string $userId, array $parties): AppointmentParty
    {
        return match ($userId) {
            $parties['client_user_id'] => AppointmentParty::Client,
            $parties['artisan_user_id'] => AppointmentParty::Artisan,
            default => throw new AppointmentRuleViolation('Rendez-vous introuvable.'),
        };
    }

    private function assertClientDeadline(AppointmentParty $party, Appointment $appointment, \DateTimeImmutable $now): void
    {
        if (AppointmentParty::Client === $party && AppointmentStatus::Confirmed === $appointment->status()
            && $appointment->startsAt() < $now->modify(\sprintf('+%d hours', self::CLIENT_CHANGE_HOURS))) {
            throw new AppointmentRuleViolation(\sprintf('Moins de %d h avant le rendez-vous : contactez le professionnel via la messagerie.', self::CLIENT_CHANGE_HOURS));
        }
    }

    private function completeRequest(string $requestId, \DateTimeImmutable $now): void
    {
        $request = $this->requests->findForUpdate(new QuoteRequestId($requestId));
        if (null === $request) {
            return;
        }
        try {
            $request->complete($now);
        } catch (RequestRuleViolation) {
            return; // déjà terminée ou close
        }
        $this->events->publish(...$request->releaseEvents());
    }
}
