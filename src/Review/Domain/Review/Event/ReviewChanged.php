<?php

declare(strict_types=1);

namespace App\Review\Domain\Review\Event;

use App\Shared\Domain\Event\DomainEvent;

/**
 * Avis déposé, modifié, publié, refusé, masqué, modification demandée, supprimé ; réponse publiée ou refusée. Sert aux
 * notifications (E-18, E-37, E-38) et au recalcul de la note de l'artisan (recherche, fiche).
 */
final readonly class ReviewChanged implements DomainEvent
{
    public function __construct(public string $reviewId, public string $artisanId, public string $change, public ?string $reason, public \DateTimeImmutable $at)
    {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
