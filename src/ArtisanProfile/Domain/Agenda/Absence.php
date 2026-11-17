<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Domain\Agenda;

/** Absence sur des journées entières (dates locales incluses) : aucun créneau n'est ouvert ces jours-là. */
final readonly class Absence
{
    public const int LABEL_MAX = 80;
    /** Une absence ne peut couvrir plus d'un an. */
    private const int MAX_DAYS = 366;

    public function __construct(
        public string $id,
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public AbsenceKind $kind,
        public ?string $label,
    ) {
        if ($to < $from) {
            throw new AgendaRuleViolation('La date de fin doit suivre la date de début.');
        }
        if ($from->diff($to)->days > self::MAX_DAYS) {
            throw new AgendaRuleViolation('Une absence ne peut pas dépasser un an : découpez-la.');
        }
        if (null !== $label && mb_strlen($label) > self::LABEL_MAX) {
            throw new AgendaRuleViolation(\sprintf('Libellé limité à %d caractères.', self::LABEL_MAX));
        }
    }

    public function covers(\DateTimeImmutable $day): bool
    {
        $d = $day->format('Y-m-d');

        return $d >= $this->from->format('Y-m-d') && $d <= $this->to->format('Y-m-d');
    }

    /** Nombre de jours couverts (bornes incluses). */
    public function days(): int
    {
        return (int) $this->from->diff($this->to)->days + 1;
    }
}
