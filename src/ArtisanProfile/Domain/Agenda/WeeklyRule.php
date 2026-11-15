<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Agenda;

/**
 * PR06.1 — plage récurrente (ex. lundi 8 h – 12 h) : jour ISO (1 = lundi … 7 = dimanche), heures locales en minutes
 * depuis minuit, pas de 15 minutes.
 */
final readonly class WeeklyRule
{
    public const int STEP = 15;
    private const int HOUR = 60;
    private const int DAY = 1440;
    public const array DAYS = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'];

    public function __construct(public int $weekday, public int $start, public int $end)
    {
        if (!isset(self::DAYS[$weekday])) {
            throw new AgendaRuleViolation('Jour de la semaine inconnu.');
        }
        if ($start < 0 || $end > self::DAY || $start >= $end) {
            throw new AgendaRuleViolation(\sprintf('%s : l’heure de fin doit suivre l’heure de début.', self::DAYS[$weekday]));
        }
        if (0 !== $start % self::STEP || 0 !== $end % self::STEP) {
            throw new AgendaRuleViolation('Les horaires se règlent au quart d’heure.');
        }
    }

    public static function fromTimes(int $weekday, string $start, string $end): self
    {
        return new self($weekday, self::parse($start), self::parse($end));
    }

    public function overlaps(self $other): bool
    {
        return $this->weekday === $other->weekday && $this->start < $other->end && $other->start < $this->end;
    }

    public function minutes(): int
    {
        return $this->end - $this->start;
    }

    public static function format(int $minutes): string
    {
        return \sprintf('%02d:%02d', intdiv($minutes, self::HOUR), $minutes % self::HOUR);
    }

    private static function parse(string $time): int
    {
        if (1 !== preg_match('/^([01]\d|2[0-4]):([0-5]\d)$/', $time, $m)) {
            throw new AgendaRuleViolation('Horaire invalide : utilisez le format HH:MM.');
        }

        return (int) $m[1] * self::HOUR + (int) $m[2];
    }

    /**
     * Vérifie un ensemble de plages (pas de chevauchement le même jour), triées par jour puis heure.
     *
     * @param list<self> $rules
     *
     * @return list<self>
     */
    public static function normalize(array $rules): array
    {
        usort($rules, static fn (self $a, self $b): int => [$a->weekday, $a->start] <=> [$b->weekday, $b->start]);
        foreach ($rules as $i => $rule) {
            if ($i > 0 && $rule->overlaps($rules[$i - 1])) {
                throw new AgendaRuleViolation(\sprintf('%s : deux plages se chevauchent.', self::DAYS[$rule->weekday]));
            }
        }

        return $rules;
    }
}
