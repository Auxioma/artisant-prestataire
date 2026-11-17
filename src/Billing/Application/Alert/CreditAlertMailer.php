<?php

declare(strict_types=1);

namespace App\Billing\Application\Alert;

/** E-42 (seuil de consommation atteint) et E-42b (crédit gratuit épuisé), e-mail et in-app. */
interface CreditAlertMailer
{
    public function thresholdReached(string $email, string $firstName, string $userId, int $consumedPercent, int $remaining, int $granted): void;

    public function freeCreditExhausted(string $email, string $firstName, string $userId, int $granted, int $remaining): void;
}
