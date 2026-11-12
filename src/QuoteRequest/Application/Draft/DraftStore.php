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

    /** P11.6 — compte créé dans le tunnel pour ce brouillon (identifiant réservé). */
    public function markPendingAccount(string $draftId, string $userId): void;

    /** P11.7 — envoi demandé avant l'activation du compte : il partira à la validation de l'e-mail (RG-040). */
    public function requestSubmission(string $draftId, \DateTimeImmutable $now): void;

    /**
     * Brouillon dont l'envoi attend l'activation de ce compte (créé dans le tunnel ou déjà connecté) ; il est rattaché
     * au compte. Renvoie son identifiant, ou null.
     */
    public function claimPendingSubmission(string $userId): ?string;

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
