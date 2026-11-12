<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Profile;

/** PR08.8 — horaires d'ouverture : jusqu'à deux plages « HH:MM-HH:MM » par jour (aucune = fermé), urgences. */
final readonly class OpeningHours
{
    public const array DAYS = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
    public const int RANGES_PER_DAY = 2;
    public const int EMERGENCY_NOTE_MAX = 200;
    private const string RANGE = '/^([01]\d|2[0-3]):[0-5]\d-([01]\d|2[0-3]):[0-5]\d$/';

    /**
     * @param array<string, list<string>> $days jour => plages
     */
    public function __construct(public array $days, public bool $emergencies, public ?string $emergencyNote)
    {
        foreach ($days as $day => $ranges) {
            if (!\in_array($day, self::DAYS, true) || \count($ranges) > self::RANGES_PER_DAY) {
                throw new ArtisanRuleViolation('Horaires : jour inconnu ou plus de deux plages.');
            }
            foreach ($ranges as $range) {
                if (1 !== preg_match(self::RANGE, $range) || substr($range, 0, 5) >= substr($range, 6)) {
                    throw new ArtisanRuleViolation(\sprintf('Horaires du %s : plage « %s » invalide (format 08:00-12:00).', $day, $range));
                }
            }
        }
        if (null !== $emergencyNote && mb_strlen($emergencyNote) > self::EMERGENCY_NOTE_MAX) {
            throw new ArtisanRuleViolation(\sprintf('Urgences : %d caractères au maximum.', self::EMERGENCY_NOTE_MAX));
        }
    }

    public function isSet(): bool
    {
        return [] !== array_filter($this->days);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['days' => $this->days, 'emergencies' => $this->emergencies, 'emergency_note' => $this->emergencyNote];
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        $days = [];
        foreach (\is_array($data['days'] ?? null) ? $data['days'] : [] as $day => $ranges) {
            if (\is_string($day) && \is_array($ranges)) {
                $days[$day] = array_values(array_filter($ranges, \is_string(...)));
            }
        }

        return new self($days, true === ($data['emergencies'] ?? false), \is_string($data['emergency_note'] ?? null) ? $data['emergency_note'] : null);
    }
}
