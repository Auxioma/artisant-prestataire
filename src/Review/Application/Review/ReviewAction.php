<?php

declare(strict_types=1);

namespace App\Review\Application\Review;

use App\Shared\Application\Bus\Command;

/** Suppression par l'auteur (C06), réponse (PR09.2) ou signalement (PR09.2) par l'artisan. */
final readonly class ReviewAction implements Command
{
    public const string DELETE = 'delete';
    public const string ANSWER = 'answer';
    public const string REPORT = 'report';

    public function __construct(public string $userId, public string $reviewId, public string $action, public ?string $text = null)
    {
    }
}
