<?php

declare(strict_types=1);

namespace App\Identity\Application\Privacy;

/** Preuves de consentement aux traceurs (CDC §11 : 6 mois) : supprimées une fois expirées. */
interface ConsentRetention
{
    public function purgeExpired(\DateTimeImmutable $now): int;
}
