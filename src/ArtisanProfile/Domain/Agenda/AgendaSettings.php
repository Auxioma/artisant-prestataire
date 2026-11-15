<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Agenda;

/**
 * Réglages de l'agenda d'un artisan (PR06.1, PR06.2) : durée d'un créneau (1 h, 2 h, demi-journée), délai de
 * prévenance minimal avant un créneau proposé, fermeture les jours fériés, mode « pause » (masque les demandes directes
 * et le badge « Disponible »).
 */
final readonly class AgendaSettings
{
    public const array SLOT_MINUTES = [60 => '1 h', 120 => '2 h', 240 => 'Demi-journée'];
    public const int DEFAULT_SLOT = 60;
    public const int DEFAULT_NOTICE_HOURS = 24;
    public const int MAX_NOTICE_HOURS = 168;

    public function __construct(
        public int $slotMinutes = self::DEFAULT_SLOT,
        public int $minNoticeHours = self::DEFAULT_NOTICE_HOURS,
        public bool $closedOnHolidays = true,
        public bool $paused = false,
    ) {
        if (!isset(self::SLOT_MINUTES[$slotMinutes])) {
            throw new AgendaRuleViolation('Durée de créneau non proposée.');
        }
        if ($minNoticeHours < 0 || $minNoticeHours > self::MAX_NOTICE_HOURS) {
            throw new AgendaRuleViolation(\sprintf('Le délai de prévenance va de 0 à %d heures.', self::MAX_NOTICE_HOURS));
        }
    }

    public function withPaused(bool $paused): self
    {
        return new self($this->slotMinutes, $this->minNoticeHours, $this->closedOnHolidays, $paused);
    }
}
