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
}
