<?php

declare(strict_types=1);

namespace App\Shared\Application\Identity;

use App\Shared\Application\Bus\Query;

/** BO03 — identité du titulaire et signaux de doublon (téléphone, appareil). Réponse : AccountReviewDetails|null. */
final readonly class AccountReview implements Query
{
    public function __construct(public string $userId)
    {
    }
}
