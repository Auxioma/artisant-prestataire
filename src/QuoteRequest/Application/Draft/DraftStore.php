<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Draft;

interface DraftStore
{
    public function find(DraftOwner $owner): ?RequestDraftView;

    /** Crée ou met à jour le brouillon (échéance RG-060 repoussée à chaque enregistrement). */
    public function save(DraftOwner $owner, RequestDraftView $draft, \DateTimeImmutable $expiresAt): void;

    public function attach(string $draftId, string $fileId, string $name, \DateTimeImmutable $now): void;

    /** Retire la pièce si elle appartient au brouillon ; false sinon. */
    public function detach(string $draftId, string $fileId): bool;

    /** Supprime le brouillon et ses rattachements (les fichiers eux-mêmes sont repris ou détruits par l'appelant). */
    public function delete(string $draftId): void;

    /**
     * Rattache au compte le brouillon d'un visiteur qui vient de se connecter (P11.6 « brouillon conservé et rattaché
     * après connexion »). Le plus récent des deux l'emporte ; renvoie les fichiers du brouillon écarté, à détruire.
     *
     * @return list<string>
     */
    public function adopt(DraftOwner $visitor, string $userId): array;
}
