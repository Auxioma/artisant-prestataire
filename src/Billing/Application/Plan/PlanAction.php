<?php

declare(strict_types=1);

namespace App\Billing\Application\Plan;

use App\Shared\Application\Bus\Command;

/** BO08.1 — publier, archiver, supprimer (brouillon jamais publié) ou déplacer un forfait dans l'ordre d'affichage. */
final readonly class PlanAction implements Command
{
    public const string PUBLISH = 'publish';
    public const string ARCHIVE = 'archive';
    public const string DELETE = 'delete';
    public const string UP = 'up';
    public const string DOWN = 'down';
    public const array ACTIONS = [self::PUBLISH, self::ARCHIVE, self::DELETE, self::UP, self::DOWN];

    public function __construct(public string $adminId, public string $planId, public string $action)
    {
    }
}
