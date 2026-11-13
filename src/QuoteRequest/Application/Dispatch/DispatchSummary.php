<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Dispatch;

/**
 * Résumé d'une demande pour les alertes artisans (E-30 / E-31) : jamais l'identité ni l'adresse du client (RG-112),
 * seulement le métier, la commune et le délai.
 */
final readonly class DispatchSummary
{
    public function __construct(
        public string $requestId,
        public string $number,
        public string $jobName,
        public string $city,
        public string $timing,
        public ?string $budget,
        public ?string $title,
    ) {
    }

    /** Ligne de digest : « Titre — Métier · Ville · Délai ». */
    public function line(): string
    {
        $line = $this->jobName.' · '.$this->city.' · '.$this->timing;

        return null === $this->title ? $line : $this->title.' — '.$line;
    }
}
