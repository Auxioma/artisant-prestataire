<?php

declare(strict_types=1);

namespace App\Billing\Domain\Plan;

/** BO08.1 — cycle d'un forfait : brouillon (invisible), publié (souscriptible), archivé (plus proposé, abonnés gardés). */
enum PlanStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Published => 'Publié',
            self::Archived => 'Archivé',
        };
    }
}
