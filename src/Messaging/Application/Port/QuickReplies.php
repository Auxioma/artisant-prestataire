<?php

declare(strict_types=1);

namespace App\Messaging\Application\Port;

/** PR07.1 — réponses types enregistrées par l'artisan (`messaging.quick_reply`). */
interface QuickReplies
{
    /** Nombre maximal de réponses types par compte. */
    public const int MAX = 20;
    public const int TITLE_MAX = 60;
    public const int BODY_MAX = 2000;

    /** @return list<array{id: string, title: string, body: string}> */
    public function of(string $userId): array;

    public function add(string $id, string $userId, string $title, string $body, \DateTimeImmutable $at): void;

    /** Vrai si la réponse existait et appartenait à l'utilisateur. */
    public function remove(string $id, string $userId): bool;
}
