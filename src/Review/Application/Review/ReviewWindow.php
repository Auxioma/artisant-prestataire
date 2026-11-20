<?php

declare(strict_types=1);

namespace App\Review\Application\Review;

/**
 * RG-034 — fenêtre de dépôt : à partir de la prestation (dernier rendez-vous réalisé ou commencé), sinon de
 * l'acceptation + 7 jours, et pendant `reviews.window_days` jours.
 */
final class ReviewWindow
{
    private const string WITHOUT_APPOINTMENT = '+7 days';

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} [ouverture, fermeture] */
    public static function of(\DateTimeImmutable $acceptedAt, ?\DateTimeImmutable $serviceAt, int $windowDays): array
    {
        $opens = $serviceAt ?? $acceptedAt->modify(self::WITHOUT_APPOINTMENT);

        return [$opens, $opens->modify(\sprintf('+%d days', $windowDays))];
    }
}
