<?php

declare(strict_types=1);

namespace App\Administration\Application\Dashboard;

final readonly class DashboardKpisView
{
    /**
     * @param list<KpiCard> $featured cartes de la maquette, dans l'ordre
     * @param list<KpiCard> $others   autres indicateurs du CDC
     * @param list<KpiCard> $finance  cartes de l'onglet Finance (BO15.3)
     */
    public function __construct(
        public string $month,
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public \DateTimeImmutable $previousFrom,
        public \DateTimeImmutable $previousTo,
        public array $featured,
        public array $others,
        public array $finance = [],
    ) {
    }

    /** Dernier jour couvert (la fin de période est exclue). */
    public function lastDay(): \DateTimeImmutable
    {
        return $this->to->modify('-1 second');
    }

    /** @return list<KpiCard> */
    public function all(): array
    {
        return [...$this->featured, ...$this->others];
    }
}
