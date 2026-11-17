<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Draft;

use App\Shared\Application\Bus\Command;

/** Enregistrement d'une étape : `complete` (bouton « Continuer ») valide l'étape et débloque la suivante ; sinon (sauvegarde automatique toutes les 10 s) seules les valeurs valides sont conservées, sans rien exiger. */
final readonly class SaveDraftStep implements Command
{
    /** @param array<array-key, mixed> $input saisie brute du formulaire */
    public function __construct(public DraftOwner $owner, public string $draftId, public int $step, public array $input, public bool $complete)
    {
    }
}
